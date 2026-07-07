<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\ParameterType;

/**
 * Consultas de lectura para dashboard e informes. Todo DBAL sobre el esquema
 * SQL-first: la fusión "Garmin si hay, móvil si no" vive en las vistas
 * v_steps_* de la base de datos, no aquí.
 */
final class HealthRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Pasos diarios fusionados + meta propia + % cumplido + reparto de fuentes. */
    public function dailySteps(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT day, steps, daily_goal, pct_goal, goal_met, garmin_buckets, phone_buckets
             FROM v_steps_daily
             WHERE day BETWEEN :f AND :t
             ORDER BY day',
            ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')],
        );
    }

    /** Sueño (duración, score, fases, HRV nocturno). */
    public function sleep(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT day, duration_s, score, deep_s, light_s, rem_s, awake_s, hrv_avg_ms
             FROM garmin_sleep
             WHERE day BETWEEN :f AND :t
             ORDER BY day',
            ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')],
        );
    }

    /** HRV diario con estado y línea base. */
    public function hrv(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT day, last_night_avg_ms, status, weekly_avg_ms, baseline_low_ms, baseline_high_ms
             FROM garmin_hrv
             WHERE day BETWEEN :f AND :t
             ORDER BY day',
            ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')],
        );
    }

    /** Resumen diario Garmin (FC reposo, estrés, minutos de intensidad, kcal). */
    public function garminDaily(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT day, resting_hr, avg_stress, active_kcal, bmr_kcal,
                    intensity_min_moderate, intensity_min_vigorous
             FROM garmin_daily
             WHERE day BETWEEN :f AND :t
             ORDER BY day',
            ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')],
        );
    }

    /** Composición corporal (báscula). */
    public function bodyComposition(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT day, weight_kg, body_fat_pct, muscle_mass_kg
             FROM garmin_body_composition
             WHERE day BETWEEN :f AND :t
             ORDER BY day',
            ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')],
        );
    }

    /** Actividades del rango, más recientes primero. */
    public function activities(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT activity_id, activity_type, activity_name, start_time,
                    duration_s, distance_m, avg_hr, max_hr, calories,
                    elevation_gain, is_strength
             FROM garmin_activity
             WHERE start_time >= :f AND start_time < (:t::date + INTERVAL '1 day')
             ORDER BY start_time DESC",
            ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')],
        );
    }

    /** Series de fuerza de una lista de actividades (para el detalle del informe). */
    public function strengthSets(array $activityIds): array
    {
        if ([] === $activityIds) {
            return [];
        }

        return $this->db->fetchAllAssociative(
            'SELECT activity_id, set_order, exercise_name, reps, weight_kg, set_type
             FROM garmin_strength_set
             WHERE activity_id IN (:ids) AND set_type = \'ACTIVE\'
             ORDER BY activity_id, set_order',
            ['ids' => $activityIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /** Mesociclo vigente en una fecha (o null si no hay bloque activo). */
    public function currentMesocycle(\DateTimeInterface $date): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, objective, date_from, date_to, steps_goal,
                    strength_sessions_week, swim_sessions_week, notes
             FROM mesocycle
             WHERE :d BETWEEN date_from AND date_to
             ORDER BY id DESC LIMIT 1',
            ['d' => $date->format('Y-m-d')],
        );

        return false === $row ? null : $row;
    }

    /** Mesociclos que solapan un rango de fechas (para dar a cada semana la pauta del bloque al que pertenece). */
    public function mesocyclesInRange(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id, objective, date_from, date_to, steps_goal,
                    strength_sessions_week, swim_sessions_week, notes
             FROM mesocycle
             WHERE date_from <= :t AND date_to >= :f
             ORDER BY date_from',
            ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')],
        );
    }

    /** Resumen semanal (semanas ISO): pasos, sesiones, peso, recuperación. */
    public function weeklySummary(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT * FROM v_weekly_summary
             WHERE week_start BETWEEN :f AND :t
             ORDER BY week_start',
            ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')],
        );
    }

    // -------------------------------------------------------------------------
    // Dashboard v2: vista principal
    // -------------------------------------------------------------------------

    /** Pasos por día desglosados por fuente (para la gráfica apilada). */
    public function dailyStepsBySource(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT (bucket_start AT TIME ZONE 'Europe/Madrid')::date AS day,
                    SUM(steps) FILTER (WHERE source = 'garmin') AS garmin_steps,
                    SUM(steps) FILTER (WHERE source = 'phone')  AS phone_steps
             FROM v_steps_fused_15m
             WHERE (bucket_start AT TIME ZONE 'Europe/Madrid')::date BETWEEN :f AND :t
             GROUP BY 1 ORDER BY 1",
            ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')],
        );
    }

    /** Estado de recuperación de hoy: última HRV y último sueño registrados. */
    public function recoveryToday(): array
    {
        return $this->recoveryAsOf(new \DateTimeImmutable('today'));
    }

    /**
     * Estado de recuperación a fecha de $day: última HRV y último sueño con
     * day <= $day. Usada por el semáforo del propietario (con $day = hoy) y
     * por el panel de invitado de un enlace compartido (con $day = último
     * día del rango congelado), para no filtrar HRV/sueño posteriores al
     * rango que el invitado no debería ver.
     */
    public function recoveryAsOf(\DateTimeInterface $day): array
    {
        $d = $day->format('Y-m-d');
        $hrv = $this->db->fetchAssociative(
            'SELECT day, last_night_avg_ms, status, baseline_low_ms, baseline_high_ms
             FROM garmin_hrv WHERE day <= :d ORDER BY day DESC LIMIT 1',
            ['d' => $d],
        ) ?: null;
        $sleep = $this->db->fetchAssociative(
            'SELECT day, duration_s, score FROM garmin_sleep WHERE day <= :d ORDER BY day DESC LIMIT 1',
            ['d' => $d],
        ) ?: null;

        return ['hrv' => $hrv, 'sleep' => $sleep];
    }

    /** Minutos de intensidad por semana (Garmin cuenta los vigorosos doble). */
    public function weeklyIntensity(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT date_trunc('week', day)::date AS week_start,
                    COALESCE(SUM(intensity_min_moderate), 0)     AS moderate,
                    COALESCE(SUM(intensity_min_vigorous), 0) * 2 AS vigorous2
             FROM garmin_daily
             WHERE day BETWEEN :f AND :t
             GROUP BY 1 ORDER BY 1",
            ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')],
        );
    }

    // -------------------------------------------------------------------------
    // Vistas de detalle
    // -------------------------------------------------------------------------

    /** Matriz día×hora de pasos con fuente dominante (heatmap de buckets). */
    public function stepsHeatmap(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT (bucket_start AT TIME ZONE 'Europe/Madrid')::date AS day,
                    EXTRACT(HOUR FROM bucket_start AT TIME ZONE 'Europe/Madrid')::int AS hour,
                    SUM(steps) AS steps,
                    SUM(steps) FILTER (WHERE source = 'garmin') AS garmin_steps,
                    SUM(steps) FILTER (WHERE source = 'phone')  AS phone_steps
             FROM v_steps_fused_15m
             WHERE (bucket_start AT TIME ZONE 'Europe/Madrid')::date BETWEEN :f AND :t
             GROUP BY 1, 2 ORDER BY 1, 2",
            ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')],
        );
    }

    // -------------------------------------------------------------------------
    // Actividades: listado enriquecido y detalle por sesión
    // -------------------------------------------------------------------------

    /** Listado con métricas extraídas del raw (TE, carga, cadencia, SWOLF). */
    public function activitiesDetailed(\DateTimeInterface $from, \DateTimeInterface $to, ?string $type): array
    {
        $sql = "SELECT activity_id, activity_type, activity_name, start_time, duration_s,
                       distance_m, avg_hr, max_hr, calories, is_strength,
                       (raw->>'aerobicTrainingEffect')::numeric   AS te_aerobic,
                       (raw->>'anaerobicTrainingEffect')::numeric AS te_anaerobic,
                       (raw->>'activityTrainingLoad')::numeric    AS training_load,
                       COALESCE((raw->>'averageRunningCadenceInStepsPerMinute')::numeric,
                                (raw->>'averageSwimCadenceInStrokesPerMinute')::numeric) AS cadence,
                       (raw->>'averageSwolf')::numeric            AS swolf,
                       (raw->>'vO2MaxValue')::numeric             AS vo2max
                FROM garmin_activity
                WHERE start_time >= :f AND start_time < (:t::date + INTERVAL '1 day')";
        $params = ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')];
        if (null !== $type && '' !== $type) {
            $sql .= ' AND activity_type = :type';
            $params['type'] = $type;
        }

        return $this->db->fetchAllAssociative($sql.' ORDER BY start_time DESC', $params);
    }

    /**
     * Tipos de actividad existentes (para el filtro del listado). Sin rango,
     * devuelve todos los tipos (uso del propietario); con $from/$to, solo
     * los tipos presentes en ese rango, para que el filtro de un invitado no
     * ofrezca tipos de actividades fuera de su rango congelado.
     */
    public function activityTypes(?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        $sql = 'SELECT DISTINCT activity_type FROM garmin_activity';
        $params = [];
        if (null !== $from && null !== $to) {
            $sql .= " WHERE start_time >= :f AND start_time < (:t::date + INTERVAL '1 day')";
            $params = ['f' => $from->format('Y-m-d'), 't' => $to->format('Y-m-d')];
        }

        return array_column($this->db->fetchAllAssociative($sql.' ORDER BY 1', $params), 'activity_type');
    }

    public function activityById(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            "SELECT *,
                    (raw->>'aerobicTrainingEffect')::numeric   AS te_aerobic,
                    (raw->>'anaerobicTrainingEffect')::numeric AS te_anaerobic,
                    (raw->>'trainingEffectLabel')              AS te_label,
                    (raw->>'activityTrainingLoad')::numeric    AS training_load,
                    (raw->>'averageSwolf')::numeric            AS swolf,
                    (raw->>'poolLength')::numeric              AS pool_length,
                    COALESCE((raw->>'averageRunningCadenceInStepsPerMinute')::numeric,
                             (raw->>'averageSwimCadenceInStrokesPerMinute')::numeric) AS cadence
             FROM garmin_activity WHERE activity_id = :id",
            ['id' => $id],
        );

        return false === $row ? null : $row;
    }

    public function activityHrZones(int $id): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT zone, secs_in_zone, low_bpm FROM garmin_activity_hr_zone
             WHERE activity_id = :id ORDER BY zone',
            ['id' => $id],
        );
    }

    public function activitySplits(int $id): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT split_order, distance_m, duration_s, avg_hr, max_hr,
                    avg_speed_mps, strokes, swolf
             FROM garmin_activity_split WHERE activity_id = :id ORDER BY split_order',
            ['id' => $id],
        );
    }

    /** Últimos contactos: app Android y sidecar de Garmin (salud del pipeline). */
    public function syncStatus(): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT key, value_ts FROM sync_state
             WHERE key IN ('hc_app_last_sync', 'garmin_sidecar_last_run')"
        );

        return array_column($rows, 'value_ts', 'key');
    }

    // -------------------------------------------------------------------------
    // Usuario y medidas corporales (perfil)
    // -------------------------------------------------------------------------

    /** Fila única de app_user (o null si aún no se ha creado ninguna). */
    public function currentUser(): ?array
    {
        try {
            $row = $this->db->fetchAssociative(
                'SELECT id, username, display_name, coach_name FROM app_user ORDER BY id LIMIT 1'
            );
        } catch (TableNotFoundException) {
            // Orden de despliegue: en un volumen ya existente (VPS en
            // producción) db/07_users.sql solo se aplica a mano, no en el
            // primer arranque. Si todavía no se ha ejecutado, app_user no
            // existe y el dashboard/informes deben poder seguir funcionando
            // (ReportDataBuilder cae a sus literales de reserva).
            return null;
        }

        return false === $row ? null : $row;
    }

    /** Últimas medidas corporales registradas, más recientes primero. */
    public function bodyMeasurements(int $limit = 30): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT day, weight_kg, body_fat_pct, neck_cm, chest_cm, waist_cm,
                    hip_cm, arm_cm, thigh_cm, note
             FROM body_measurement
             ORDER BY day DESC LIMIT :limit',
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );
    }

    /** Objetivo de pasos vigente hoy (la fila con mayor valid_from <= hoy). */
    public function currentStepGoal(): ?int
    {
        $value = $this->db->fetchOne(
            'SELECT daily_goal FROM step_goal
             WHERE valid_from <= CURRENT_DATE
             ORDER BY valid_from DESC LIMIT 1',
        );

        return false === $value ? null : (int) $value;
    }
}
