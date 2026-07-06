-- =============================================================================
--  06 — Fix de v_weekly_summary: semanas sin pasos desaparecían del resumen
--  y el delta de peso comparaba filas no adyacentes tras un hueco.
--
--  Este parche SUSTITUYE la definición de v_weekly_summary creada en
--  04_mesociclo.sql (líneas ~48-100). No es idempotente por sí solo respecto
--  a 04: aplicar SIEMPRE después de 04_mesociclo.sql, y aplicarlo A MANO sobre
--  bases de datos ya existentes, porque docker-entrypoint-initdb.d solo
--  ejecuta los scripts en el primer arranque del contenedor (una base ya
--  inicializada no vuelve a leer db/*.sql automáticamente):
--    docker compose exec -T db psql -U health -d health < db/06_weekly_summary_fix.sql
--
--  Bugs corregidos (misma lista de columnas y tipos que la vista original,
--  por lo que un CREATE OR REPLACE VIEW es seguro y no rompe a los
--  consumidores — symfony/src/Repository/HealthRepository.php y
--  symfony/src/Service/ReportDataBuilder.php leen esta vista con SELECT *
--  y acceso por nombre de columna):
--
--  (a) La vista partía de `pasos` (derivada de v_steps_daily) y encadenaba
--      LEFT JOIN sesiones/peso/recuperacion sobre ese conjunto de semanas.
--      Una semana sin ningún paso registrado (reloj sin llevar) desaparecía
--      POR COMPLETO del resultado aunque hubiera sesión de entrenamiento,
--      pesaje o sueño/HRV esa semana.
--  (b) weight_delta se calculaba con LAG(avg_weight) OVER (ORDER BY
--      p.week_start), es decir sobre el orden de filas del resultado final
--      (el de `pasos`). Si una semana intermedia se caía por (a), el LAG
--      comparaba el peso contra una semana NO adyacente en el calendario,
--      dando un delta silenciosamente incorrecto.
-- =============================================================================
BEGIN;

CREATE OR REPLACE VIEW v_weekly_summary AS
WITH pasos AS (
    SELECT date_trunc('week', day)::date            AS week_start,
           ROUND(AVG(steps))                        AS avg_steps,
           COUNT(*) FILTER (WHERE goal_met)         AS days_goal_met,
           COUNT(*)                                 AS days_with_data
    FROM v_steps_daily
    GROUP BY 1
),
sesiones AS (
    SELECT date_trunc('week', (start_time AT TIME ZONE 'Europe/Madrid'))::date AS week_start,
           COUNT(*) FILTER (WHERE is_strength)                                 AS strength_sessions,
           COUNT(*) FILTER (WHERE activity_type ILIKE '%swim%')                AS swim_sessions,
           COALESCE(SUM(distance_m) FILTER (WHERE activity_type ILIKE '%swim%'), 0) AS swim_m,
           COALESCE(SUM(duration_s) / 60.0, 0)::int                            AS total_min
    FROM garmin_activity
    GROUP BY 1
),
-- Peso: esta CTE ya contiene únicamente semanas con al menos un pesaje real
-- (viene de un GROUP BY sobre garmin_body_composition, no del universo de
-- semanas). Por eso el delta se calcula AQUÍ, con LAG ordenado por el
-- week_start de esta misma CTE: el "anterior" del LAG es, por construcción,
-- la semana de pesaje inmediatamente anterior en el tiempo, nunca una
-- semana intermedia sin báscula. Semántica: "cuánto cambió el peso medio
-- desde el último pesaje registrado", no "vs la fila de arriba en el
-- resultado final" (eso era el bug (b) — ambigüedad resuelta a favor de
-- comparar semanas de pesaje consecutivas, que es lo que tiene sentido
-- para seguimiento de composición corporal).
peso AS (
    SELECT week_start,
           avg_weight,
           avg_weight - LAG(avg_weight) OVER (ORDER BY week_start) AS weight_delta
    FROM (
        SELECT date_trunc('week', day)::date AS week_start,
               ROUND(AVG(weight_kg), 2)      AS avg_weight
        FROM garmin_body_composition
        GROUP BY 1
    ) w
),
recuperacion AS (
    SELECT date_trunc('week', COALESCE(s.day, h.day))::date AS week_start,
           ROUND(AVG(s.score))                              AS avg_sleep_score,
           ROUND(AVG(s.duration_s) / 3600.0, 1)             AS avg_sleep_h,
           ROUND(AVG(h.last_night_avg_ms))                  AS avg_hrv_ms
    FROM garmin_sleep s
    FULL OUTER JOIN garmin_hrv h USING (day)
    GROUP BY 1
),
-- Universo real de semanas: la unión de las cuatro fuentes. Esto es lo que
-- corrige el bug (a) — una semana sin pasos pero con sesión, pesaje o
-- sueño/HRV ya no se pierde, solo aparece con NULL en las columnas que
-- efectivamente no tienen dato esa semana.
semanas AS (
    SELECT week_start FROM pasos
    UNION
    SELECT week_start FROM sesiones
    UNION
    SELECT week_start FROM peso
    UNION
    SELECT week_start FROM recuperacion
)
SELECT
    sem.week_start,
    p.avg_steps,
    p.days_goal_met,
    p.days_with_data,
    COALESCE(a.strength_sessions, 0)                    AS strength_sessions,
    COALESCE(a.swim_sessions, 0)                        AS swim_sessions,
    COALESCE(a.swim_m, 0)                               AS swim_m,
    COALESCE(a.total_min, 0)                            AS training_min,
    w.avg_weight,
    w.weight_delta                                      AS weight_delta,
    r.avg_sleep_score,
    r.avg_sleep_h,
    r.avg_hrv_ms
FROM semanas sem
LEFT JOIN pasos p        USING (week_start)
LEFT JOIN sesiones a     USING (week_start)
LEFT JOIN peso w         USING (week_start)
LEFT JOIN recuperacion r USING (week_start)
ORDER BY sem.week_start;

COMMIT;
