-- =============================================================================
--  09 — Zona horaria centralizada.
--
--  'Europe/Madrid' estaba repetido a mano en los índices por día
--  (idx_garmin_bucket_day, idx_hc_bucket_day, db/02_fixes.sql), en las
--  vistas v_steps_daily_fused (db/02_fixes.sql) y v_weekly_summary
--  (db/06_weekly_summary_fix.sql), y en HealthRepository.php
--  (dailyStepsBySource, stepsHeatmap). Cambiar de zona horaria significaba
--  tocar media docena de sitios y arriesgarse a dejar alguno desactualizado.
--
--  A partir de aquí hay UNA sola fuente de verdad: la función app_timezone().
--  Para cambiar la zona horaria de la app:
--    1. Edita el literal 'Europe/Madrid' de app_timezone() más abajo.
--    2. Reaplica este fichero (psql -f db/09_timezone.sql; si ya está
--       registrado en schema_migration, bórralo de ahí primero o aplícalo
--       a mano con psql — el runner solo aplica ficheros nuevos).
--    3. Reconstruye los dos índices de corte de día — su definición no
--       cambia (siguen llamando a app_timezone()) pero las entradas ya
--       construidas con la zona anterior quedan obsoletas:
--         REINDEX INDEX idx_garmin_bucket_day;
--         REINDEX INDEX idx_hc_bucket_day;
--
--  Por qué no se tocan db/02_fixes.sql, db/04_mesociclo.sql ni
--  db/06_weekly_summary_fix.sql para que referencien app_timezone(): en un
--  volumen nuevo, docker-entrypoint-initdb.d los ejecuta en orden
--  alfabético ANTES que este fichero, y fallarían con "function
--  app_timezone() does not exist". Este fichero se limita a recrear (con
--  CREATE OR REPLACE VIEW, sin tocar sus dependientes) lo que esos ficheros
--  ya crearon con el literal.
--
--  Nota IMMUTABLE: la función no lee tablas ni parámetros de sesión, solo
--  devuelve un literal fijo en el código, así que la marca es honesta — pero
--  side effect: como es IMMUTABLE, Postgres puede cachear su resultado
--  dentro de una misma consulta y usarla en índices de expresión (obligatorio
--  para los dos índices de corte de día). Que siga siendo "verdad" IMMUTABLE
--  depende de reaplicar este fichero + REINDEX cada vez que cambies el
--  literal, no de que Postgres detecte el cambio solo.
-- =============================================================================
BEGIN;

CREATE OR REPLACE FUNCTION app_timezone() RETURNS text
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    AS $$ SELECT 'Europe/Madrid'::text $$;

-- -----------------------------------------------------------------------------
-- v_steps_daily_fused: misma definición vigente (db/02_fixes.sql), con
-- app_timezone() en vez del literal. Mismas columnas/tipos que la versión
-- actual → CREATE OR REPLACE VIEW es seguro (v_steps_daily, que lee de
-- esta vista, no necesita tocarse).
-- -----------------------------------------------------------------------------
CREATE OR REPLACE VIEW v_steps_daily_fused AS
SELECT
    (bucket_start AT TIME ZONE app_timezone())::date         AS day,
    SUM(steps)                                                AS steps,
    COUNT(*) FILTER (WHERE source = 'garmin')                 AS garmin_buckets,
    COUNT(*) FILTER (WHERE source = 'phone')                  AS phone_buckets
FROM v_steps_fused_15m
GROUP BY 1
ORDER BY 1;

-- -----------------------------------------------------------------------------
-- v_weekly_summary: misma definición vigente (db/06_weekly_summary_fix.sql,
-- NO la de 04_mesociclo.sql, superada por 06), con app_timezone() en vez
-- del literal en la CTE `sesiones`. Mismas columnas/tipos → CREATE OR
-- REPLACE VIEW es seguro (HealthRepository::weeklySummary() y
-- ReportDataBuilder leen esta vista con SELECT * / por nombre de columna).
-- -----------------------------------------------------------------------------
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
    SELECT date_trunc('week', (start_time AT TIME ZONE app_timezone()))::date AS week_start,
           COUNT(*) FILTER (WHERE is_strength)                                 AS strength_sessions,
           COUNT(*) FILTER (WHERE activity_type ILIKE '%swim%')                AS swim_sessions,
           COALESCE(SUM(distance_m) FILTER (WHERE activity_type ILIKE '%swim%'), 0) AS swim_m,
           COALESCE(SUM(duration_s) / 60.0, 0)::int                            AS total_min
    FROM garmin_activity
    GROUP BY 1
),
-- Peso: ver comentario original en db/06_weekly_summary_fix.sql — el delta
-- se calcula aquí, sobre el orden de esta misma CTE (semanas con pesaje
-- real), no sobre el resultado final.
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
-- Universo real de semanas: unión de las cuatro fuentes (ver bug (a) en
-- db/06_weekly_summary_fix.sql).
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

-- -----------------------------------------------------------------------------
-- Índices por día: mismo patrón DROP+CREATE de db/02_fixes.sql (Postgres no
-- tiene CREATE INDEX IF NOT EXISTS ... OR REPLACE), ahora con
-- app_timezone(). Válido como expresión de índice porque la función es
-- IMMUTABLE.
-- -----------------------------------------------------------------------------
DROP INDEX IF EXISTS idx_garmin_bucket_day;
DROP INDEX IF EXISTS idx_hc_bucket_day;

CREATE INDEX idx_garmin_bucket_day
    ON garmin_steps_bucket (((bucket_start AT TIME ZONE app_timezone())::date));
CREATE INDEX idx_hc_bucket_day
    ON hc_movement_bucket (((bucket_start AT TIME ZONE app_timezone())::date));

COMMIT;
