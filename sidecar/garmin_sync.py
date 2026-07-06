"""
Ingesta de Garmin Connect → PostgreSQL (tablas garmin_*).

Autenticación con garth: el MFA se hace UNA vez con bootstrap_garmin.py, que
persiste el blob de tokens en garmin_auth_token. Aquí solo se carga/refresca.

Ventana rodante: relee los últimos GARMIN_DAYS_BACK días (por defecto 3,
porque Garmin recalcula sueño/HRV hasta 24-48h después). Primer arranque o
backfill: run(days_back=30).

Reglas clave:
  - garmin_steps_bucket: solo se insertan buckets con steps > 0. La API
    intradía devuelve el día entero relleno de ceros aunque no lleves el
    reloj; insertarlos contaminaría la fusión y la cobertura.
  - Todo upsert es idempotente; el JSON crudo se guarda en `raw`.
  - Cada sección va en su propio try/except: un endpoint caído no tumba
    el resto de la ingesta.
"""

import json
import logging
import os
import sys
from datetime import date, datetime, timedelta, timezone
from zoneinfo import ZoneInfo

import garth
import psycopg

log = logging.getLogger("garmin_sync")
logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")

PG_DSN = os.environ.get("PG_DSN")
if not PG_DSN:
    raise RuntimeError("Falta la variable de entorno PG_DSN (debe definirla docker-compose).")
DAYS_BACK = int(os.environ.get("GARMIN_DAYS_BACK", "3"))
MADRID_TZ = ZoneInfo("Europe/Madrid")


# ---------------------------------------------------------------------------
# Autenticación
# ---------------------------------------------------------------------------

def load_auth(conn: psycopg.Connection) -> None:
    row = conn.execute("SELECT oauth1_token FROM garmin_auth_token WHERE id = 1").fetchone()
    if row is None:
        raise RuntimeError("Sin token de Garmin: ejecuta primero bootstrap_garmin.py (MFA una sola vez).")
    garth.client.loads(row[0])


def save_auth(conn: psycopg.Connection) -> None:
    """Persiste los tokens (garth refresca el OAuth2 solo; hay que reguardarlo)."""
    conn.execute(
        "UPDATE garmin_auth_token SET oauth1_token = %s WHERE id = 1",
        (garth.client.dumps(),),
    )


def api(path: str):
    return garth.connectapi(path)

_DISPLAY_NAME: str | None = None


def display_name() -> str:
    """Los endpoints de usersummary/wellness exigen el displayName del perfil
    (identificador UUID), NO el email: con el email devuelven 403 Forbidden."""
    global _DISPLAY_NAME
    if _DISPLAY_NAME is None:
        profile = api("/userprofile-service/socialProfile") or {}
        _DISPLAY_NAME = profile.get("displayName") or garth.client.username
    return _DISPLAY_NAME



# ---------------------------------------------------------------------------
# Secciones de ingesta (cada una idempotente e independiente)
# ---------------------------------------------------------------------------

def sync_daily(conn, day: date) -> None:
    d = day.isoformat()
    data = api(f"/usersummary-service/usersummary/daily/{display_name()}?calendarDate={d}")
    if not data:
        return
    conn.execute(
        """
        INSERT INTO garmin_daily (day, steps, distance_m, floors, active_kcal, bmr_kcal,
                                  resting_hr, min_hr, max_hr, avg_stress,
                                  intensity_min_moderate, intensity_min_vigorous, status, raw)
        VALUES (%(day)s, %(steps)s, %(dist)s, %(floors)s, %(akcal)s, %(bkcal)s,
                %(rhr)s, %(minhr)s, %(maxhr)s, %(stress)s, %(imod)s, %(ivig)s, 'complete', %(raw)s)
        ON CONFLICT (day) DO UPDATE SET
            steps = EXCLUDED.steps, distance_m = EXCLUDED.distance_m, floors = EXCLUDED.floors,
            active_kcal = EXCLUDED.active_kcal, bmr_kcal = EXCLUDED.bmr_kcal,
            resting_hr = EXCLUDED.resting_hr, min_hr = EXCLUDED.min_hr, max_hr = EXCLUDED.max_hr,
            avg_stress = EXCLUDED.avg_stress,
            intensity_min_moderate = EXCLUDED.intensity_min_moderate,
            intensity_min_vigorous = EXCLUDED.intensity_min_vigorous,
            status = 'complete', raw = EXCLUDED.raw, fetched_at = now()
        """,
        {
            "day": day,
            "steps": data.get("totalSteps"),
            "dist": data.get("totalDistanceMeters"),
            "floors": data.get("floorsAscended"),
            "akcal": data.get("activeKilocalories"),
            "bkcal": data.get("bmrKilocalories"),
            "rhr": data.get("restingHeartRate"),
            "minhr": data.get("minHeartRate"),
            "maxhr": data.get("maxHeartRate"),
            "stress": data.get("averageStressLevel"),
            "imod": data.get("moderateIntensityMinutes"),
            "ivig": data.get("vigorousIntensityMinutes"),
            "raw": json.dumps(data),
        },
    )


def sync_steps_buckets(conn, day: date) -> None:
    """Buckets de 15 min alineados a reloj. Solo se INSERTAN buckets con steps > 0
    (regla anti-cero: la API intradía rellena el día entero de ceros aunque no
    lleves el reloj puesto). Los buckets que llegan a 0 no se insertan, pero si
    ya existían en BD con steps > 0 se corrigen a 0 en una sola sentencia UPDATE
    (Garmin puede revisar datos a la baja tras el primer fetch)."""
    d = day.isoformat()
    data = api(f"/wellness-service/wellness/dailySummaryChart/{display_name()}?date={d}")
    if not data:
        return
    zero_starts = []
    for b in data:
        steps = b.get("steps") or 0
        start = _parse_gmt(b.get("startGMT"))
        if start is None:
            continue
        if steps <= 0:
            zero_starts.append(start)
            continue
        end = _parse_gmt(b.get("endGMT"))
        conn.execute(
            """
            INSERT INTO garmin_steps_bucket (bucket_start, bucket_end, steps, activity_level)
            VALUES (%s, %s, %s, %s)
            ON CONFLICT (bucket_start) DO UPDATE SET
                bucket_end = EXCLUDED.bucket_end, steps = EXCLUDED.steps,
                activity_level = EXCLUDED.activity_level, fetched_at = now()
            """,
            (start, end, int(steps), b.get("primaryActivityLevel")),
        )
    if zero_starts:
        conn.execute(
            """
            UPDATE garmin_steps_bucket SET steps = 0, fetched_at = now()
            WHERE bucket_start = ANY(%s) AND steps <> 0
            """,
            (zero_starts,),
        )


def sync_sleep(conn, day: date) -> None:
    d = day.isoformat()
    data = api(
        f"/wellness-service/wellness/dailySleepData/{display_name()}"
        f"?date={d}&nonSleepBufferMinutes=60"
    )
    dto = (data or {}).get("dailySleepDTO") or {}
    if not dto.get("sleepTimeSeconds"):
        return
    score = ((dto.get("sleepScores") or {}).get("overall") or {}).get("value")
    conn.execute(
        """
        INSERT INTO garmin_sleep (day, duration_s, score, deep_s, light_s, rem_s, awake_s,
                                  hrv_avg_ms, respiration_avg, spo2_avg, raw)
        VALUES (%(day)s, %(dur)s, %(score)s, %(deep)s, %(light)s, %(rem)s, %(awake)s,
                %(hrv)s, %(resp)s, %(spo2)s, %(raw)s)
        ON CONFLICT (day) DO UPDATE SET
            duration_s = EXCLUDED.duration_s, score = EXCLUDED.score,
            deep_s = EXCLUDED.deep_s, light_s = EXCLUDED.light_s, rem_s = EXCLUDED.rem_s,
            awake_s = EXCLUDED.awake_s, hrv_avg_ms = EXCLUDED.hrv_avg_ms,
            respiration_avg = EXCLUDED.respiration_avg, spo2_avg = EXCLUDED.spo2_avg,
            raw = EXCLUDED.raw, fetched_at = now()
        """,
        {
            "day": day,
            "dur": dto.get("sleepTimeSeconds"),
            "score": score,
            "deep": dto.get("deepSleepSeconds"),
            "light": dto.get("lightSleepSeconds"),
            "rem": dto.get("remSleepSeconds"),
            "awake": dto.get("awakeSleepSeconds"),
            "hrv": _to_int(dto.get("avgOvernightHrv")),
            "resp": dto.get("averageRespirationValue"),
            "spo2": dto.get("averageSpO2Value"),
            "raw": json.dumps(data),
        },
    )


def sync_hrv(conn, day: date) -> None:
    data = api(f"/hrv-service/hrv/{day.isoformat()}")
    summary = (data or {}).get("hrvSummary") or {}
    if not summary.get("lastNightAvg"):
        return
    baseline = summary.get("baseline") or {}
    conn.execute(
        """
        INSERT INTO garmin_hrv (day, last_night_avg_ms, last_night_high_ms, status,
                                weekly_avg_ms, baseline_low_ms, baseline_high_ms, raw)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        ON CONFLICT (day) DO UPDATE SET
            last_night_avg_ms = EXCLUDED.last_night_avg_ms,
            last_night_high_ms = EXCLUDED.last_night_high_ms,
            status = EXCLUDED.status, weekly_avg_ms = EXCLUDED.weekly_avg_ms,
            baseline_low_ms = EXCLUDED.baseline_low_ms, baseline_high_ms = EXCLUDED.baseline_high_ms,
            raw = EXCLUDED.raw, fetched_at = now()
        """,
        (
            day,
            summary.get("lastNightAvg"),
            summary.get("lastNight5MinHigh"),
            summary.get("status"),
            summary.get("weeklyAvg"),
            baseline.get("balancedLow"),
            baseline.get("balancedUpper"),
            json.dumps(data),
        ),
    )


def sync_body_composition(conn, day: date) -> None:
    data = api(f"/weight-service/weight/dayview/{day.isoformat()}")
    entries = (data or {}).get("dateWeightList") or []
    if not entries:
        return
    w = entries[0]  # última pesada del día
    conn.execute(
        """
        INSERT INTO garmin_body_composition (day, weight_kg, bmi, body_fat_pct,
                                             muscle_mass_kg, body_water_pct, bone_mass_kg, raw)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        ON CONFLICT (day) DO UPDATE SET
            weight_kg = EXCLUDED.weight_kg, bmi = EXCLUDED.bmi,
            body_fat_pct = EXCLUDED.body_fat_pct, muscle_mass_kg = EXCLUDED.muscle_mass_kg,
            body_water_pct = EXCLUDED.body_water_pct, bone_mass_kg = EXCLUDED.bone_mass_kg,
            raw = EXCLUDED.raw, fetched_at = now()
        """,
        (
            day,
            _grams_to_kg(w.get("weight")),
            w.get("bmi"),
            w.get("bodyFat"),
            _grams_to_kg(w.get("muscleMass")),
            w.get("bodyWater"),
            _grams_to_kg(w.get("boneMass")),
            json.dumps(w),
        ),
    )


def sync_vo2max(conn, day: date) -> None:
    d = day.isoformat()
    data = api(f"/metrics-service/metrics/maxmet/daily/{d}/{d}")
    if not data:
        return
    entry = data[0] if isinstance(data, list) else data
    running = ((entry.get("generic") or {}).get("vo2MaxPreciseValue"))
    cycling = ((entry.get("cycling") or {}).get("vo2MaxPreciseValue"))
    if running is None and cycling is None:
        return
    conn.execute(
        """
        INSERT INTO garmin_vo2max (day, vo2max_running, vo2max_cycling, raw)
        VALUES (%s, %s, %s, %s)
        ON CONFLICT (day) DO UPDATE SET
            vo2max_running = EXCLUDED.vo2max_running,
            vo2max_cycling = EXCLUDED.vo2max_cycling,
            raw = EXCLUDED.raw, fetched_at = now()
        """,
        (day, running, cycling, json.dumps(entry)),
    )


def sync_activities(conn, since: date) -> None:
    """Actividades recientes + detalle de series de fuerza (solo API, no existe en HC).

    La API devuelve las actividades en orden descendente por fecha (la más
    reciente primero); paginamos hacia atrás y paramos en cuanto una página
    sale de la ventana de sincronización (o llega corta/vacía)."""
    page_size = 50
    max_pages = 20
    activities: list = []
    for page in range(max_pages):
        start_index = page * page_size
        batch = api(
            f"/activitylist-service/activities/search/activities"
            f"?limit={page_size}&start={start_index}"
        ) or []
        if not batch:
            break
        activities.extend(batch)
        oldest_start = _parse_gmt(batch[-1].get("startTimeGMT"))
        if len(batch) < page_size:
            break
        if oldest_start is not None and _to_madrid_date(oldest_start) < since:
            break

    for a in activities:
        start = _parse_gmt(a.get("startTimeGMT"))
        if start is None or _to_madrid_date(start) < since:
            continue
        type_key = ((a.get("activityType") or {}).get("typeKey") or "").lower()
        is_strength = "strength" in type_key
        conn.execute(
            """
            INSERT INTO garmin_activity (activity_id, activity_type, activity_name, start_time,
                                         duration_s, distance_m, avg_hr, max_hr, calories,
                                         avg_speed_mps, elevation_gain, is_strength, raw)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON CONFLICT (activity_id) DO UPDATE SET
                activity_type = EXCLUDED.activity_type, activity_name = EXCLUDED.activity_name,
                start_time = EXCLUDED.start_time, duration_s = EXCLUDED.duration_s,
                distance_m = EXCLUDED.distance_m, avg_hr = EXCLUDED.avg_hr,
                max_hr = EXCLUDED.max_hr, calories = EXCLUDED.calories,
                avg_speed_mps = EXCLUDED.avg_speed_mps, elevation_gain = EXCLUDED.elevation_gain,
                is_strength = EXCLUDED.is_strength, raw = EXCLUDED.raw, updated_at = now()
            """,
            (
                a.get("activityId"),
                type_key,
                a.get("activityName"),
                start,
                a.get("duration"),
                a.get("distance"),
                _to_int(a.get("averageHR")),
                _to_int(a.get("maxHR")),
                _to_int(a.get("calories")),
                a.get("averageSpeed"),
                a.get("elevationGain"),
                is_strength,
                json.dumps(a),
            ),
        )
        if is_strength:
            _sync_strength_sets(conn, a.get("activityId"))
        _sync_activity_detail(conn, a.get("activityId"))


def _sync_strength_sets(conn, activity_id: int) -> None:
    data = api(f"/activity-service/activity/{activity_id}/exerciseSets")
    sets = (data or {}).get("exerciseSets") or []
    order = 0
    for s in sets:
        order += 1
        exercises = s.get("exercises") or [{}]
        conn.execute(
            """
            INSERT INTO garmin_strength_set (activity_id, set_order, exercise_name, category,
                                             reps, weight_kg, duration_s, set_type, raw)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON CONFLICT (activity_id, set_order) DO UPDATE SET
                exercise_name = EXCLUDED.exercise_name, category = EXCLUDED.category,
                reps = EXCLUDED.reps, weight_kg = EXCLUDED.weight_kg,
                duration_s = EXCLUDED.duration_s, set_type = EXCLUDED.set_type,
                raw = EXCLUDED.raw
            """,
            (
                activity_id,
                order,
                exercises[0].get("name"),
                exercises[0].get("category"),
                s.get("repetitionCount"),
                _grams_to_kg(s.get("weight")),  # Garmin devuelve gramos
                s.get("duration"),
                s.get("setType"),
                json.dumps(s),
            ),
        )



def _sync_activity_detail(conn, activity_id: int) -> None:
    """Zonas de FC y parciales/laps de una actividad. Cada bloque aislado:
    hay tipos de actividad sin splits o sin zonas y no debe romper nada."""
    try:
        zones = api(f"/activity-service/activity/{activity_id}/hrTimeInZones") or []
        for z in zones:
            zone_n = z.get("zoneNumber")
            if zone_n is None:
                continue
            conn.execute(
                """
                INSERT INTO garmin_activity_hr_zone (activity_id, zone, secs_in_zone, low_bpm)
                VALUES (%s, %s, %s, %s)
                ON CONFLICT (activity_id, zone) DO UPDATE SET
                    secs_in_zone = EXCLUDED.secs_in_zone, low_bpm = EXCLUDED.low_bpm
                """,
                (activity_id, int(zone_n), _to_int(z.get("secsInZone")) or 0,
                 _to_int(z.get("zoneLowBoundary"))),
            )
    except Exception as e:
        log.debug("Sin zonas FC para %s: %s", activity_id, e)

    try:
        data = api(f"/activity-service/activity/{activity_id}/splits") or {}
        laps = data.get("lapDTOs") or []
        for i, lap in enumerate(laps, start=1):
            conn.execute(
                """
                INSERT INTO garmin_activity_split
                    (activity_id, split_order, distance_m, duration_s, avg_hr, max_hr,
                     avg_speed_mps, strokes, swolf, raw)
                VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                ON CONFLICT (activity_id, split_order) DO UPDATE SET
                    distance_m = EXCLUDED.distance_m, duration_s = EXCLUDED.duration_s,
                    avg_hr = EXCLUDED.avg_hr, max_hr = EXCLUDED.max_hr,
                    avg_speed_mps = EXCLUDED.avg_speed_mps, strokes = EXCLUDED.strokes,
                    swolf = EXCLUDED.swolf, raw = EXCLUDED.raw
                """,
                (activity_id, i, lap.get("distance"), lap.get("duration"),
                 _to_int(lap.get("averageHR")), _to_int(lap.get("maxHR")),
                 lap.get("averageSpeed"), _to_int(lap.get("totalNumberOfStrokes")),
                 lap.get("averageSwolf"), json.dumps(lap)),
            )
    except Exception as e:
        log.debug("Sin splits para %s: %s", activity_id, e)


# ---------------------------------------------------------------------------
# Orquestación
# ---------------------------------------------------------------------------

def run(days_back: int = DAYS_BACK) -> dict:
    """Una corrida completa. Devuelve un pequeño resumen para logs/estado."""
    started = datetime.now(timezone.utc)
    days = [date.today() - timedelta(days=i) for i in range(days_back, -1, -1)]
    errors: list[str] = []

    with psycopg.connect(PG_DSN, autocommit=True) as conn:
        load_auth(conn)

        for day in days:
            for name, fn in [
                ("daily", sync_daily),
                ("steps", sync_steps_buckets),
                ("sleep", sync_sleep),
                ("hrv", sync_hrv),
                ("body", sync_body_composition),
                ("vo2max", sync_vo2max),
            ]:
                try:
                    fn(conn, day)
                except Exception as e:  # una sección caída no tumba el resto
                    errors.append(f"{name}@{day}: {e}")
                    log.warning("Fallo %s en %s: %s", name, day, e)

        try:
            sync_activities(conn, since=days[0])
        except Exception as e:
            errors.append(f"activities: {e}")
            log.warning("Fallo activities: %s", e)

        save_auth(conn)  # garth pudo refrescar el OAuth2 durante la corrida
        conn.execute(
            """
            INSERT INTO sync_state (key, value_ts, value_text)
            VALUES ('garmin_sidecar_last_run', now(), %s)
            ON CONFLICT (key) DO UPDATE SET value_ts = now(), value_text = EXCLUDED.value_text
            """,
            (f"days_back={days_back} errors={len(errors)}",),
        )

    summary = {
        "dias": len(days),
        "errores": errors,
        "duracion_s": round((datetime.now(timezone.utc) - started).total_seconds(), 1),
    }
    log.info("Ingesta Garmin terminada: %s", summary)
    return summary


# ---------------------------------------------------------------------------
# Utilidades
# ---------------------------------------------------------------------------

def _parse_gmt(value):
    """Garmin devuelve 'YYYY-MM-DDTHH:MM:SS.f' (a veces sin milis) en GMT."""
    if not value:
        return None
    for fmt in ("%Y-%m-%dT%H:%M:%S.%f", "%Y-%m-%dT%H:%M:%S", "%Y-%m-%d %H:%M:%S"):
        try:
            return datetime.strptime(value, fmt).replace(tzinfo=timezone.utc)
        except ValueError:
            continue
    return None


def _to_madrid_date(dt: datetime) -> date:
    """Convierte un datetime tz-aware (UTC) a fecha local Europe/Madrid, para no
    descartar actividades de madrugada por comparar fecha UTC con fecha local."""
    return dt.astimezone(MADRID_TZ).date()


def _grams_to_kg(value):
    return round(value / 1000.0, 2) if value else None


def _to_int(value):
    try:
        return int(round(float(value)))
    except (TypeError, ValueError):
        return None


if __name__ == "__main__":
    # Uso: python garmin_sync.py [days_back]  → backfill: python garmin_sync.py 30
    back = int(sys.argv[1]) if len(sys.argv) > 1 else DAYS_BACK
    try:
        run(days_back=back)
    except RuntimeError as e:
        log.error(str(e))
        sys.exit(1)
