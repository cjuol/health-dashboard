-- =============================================================================
--  Correcciones al schema.sql original — aplicar tras (o en lugar de) las
--  partes afectadas. Ver README para el detalle de cada problema.
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
-- FIX 1 (error duro): los índices por día del original NO se pueden crear.
-- `timestamptz::date` depende del TimeZone de sesión (función STABLE, no
-- IMMUTABLE) → "functions in index expression must be marked IMMUTABLE".
-- Se fija la zona explícitamente, que además hace el corte de día correcto.
-- -----------------------------------------------------------------------------
DROP INDEX IF EXISTS idx_garmin_bucket_day;
DROP INDEX IF EXISTS idx_hc_bucket_day;

CREATE INDEX idx_garmin_bucket_day
    ON garmin_steps_bucket (((bucket_start AT TIME ZONE 'Europe/Madrid')::date));
CREATE INDEX idx_hc_bucket_day
    ON hc_movement_bucket (((bucket_start AT TIME ZONE 'Europe/Madrid')::date));

-- -----------------------------------------------------------------------------
-- FIX 2 (menor): persistir el campo `device` que la app ya envía en el payload.
-- -----------------------------------------------------------------------------
ALTER TABLE hc_movement_bucket
    ADD COLUMN IF NOT EXISTS device VARCHAR(64);

-- -----------------------------------------------------------------------------
-- FIX 3 (lógica de fusión): la regla "si existe bucket Garmin, gana Garmin"
-- rompe el caso de uso principal. Empujando el carro, el Fénix SÍ registra
-- el bucket pero con ~0 pasos (muñeca inmóvil), y la API intradía de Garmin
-- suele devolver la serie completa del día con ceros incluso sin reloj.
-- Nueva regla: Garmin si tiene pasos > 0; si no, móvil. Nunca se suman
-- ambos en el mismo bucket → sigue sin haber doble conteo.
-- También: se expone distance_m (la CTE original la calculaba y la tiraba)
-- y el corte de día usa zona explícita (con sesión en UTC, los pasos de
-- 22:00–00:00 CEST caían en el día equivocado).
-- -----------------------------------------------------------------------------
DROP VIEW IF EXISTS v_steps_daily;
DROP VIEW IF EXISTS v_steps_daily_fused;
DROP VIEW IF EXISTS v_steps_fused_15m;

CREATE VIEW v_steps_fused_15m AS
WITH phone AS (
    SELECT bucket_start,
           SUM(steps)      AS steps,
           SUM(distance_m) AS distance_m
    FROM hc_movement_bucket
    GROUP BY bucket_start
)
SELECT
    COALESCE(g.bucket_start, p.bucket_start)                 AS bucket_start,
    CASE
        WHEN COALESCE(g.steps, 0) > 0 THEN 'garmin'
        WHEN p.steps IS NOT NULL     THEN 'phone'
        ELSE 'garmin'                                        -- Garmin presente con 0 y sin dato móvil
    END                                                      AS source,
    CASE
        WHEN COALESCE(g.steps, 0) > 0 THEN g.steps
        ELSE COALESCE(p.steps, g.steps)
    END                                                      AS steps,
    p.distance_m                                             AS phone_distance_m
FROM garmin_steps_bucket g
FULL OUTER JOIN phone p ON p.bucket_start = g.bucket_start;

CREATE VIEW v_steps_daily_fused AS
SELECT
    (bucket_start AT TIME ZONE 'Europe/Madrid')::date        AS day,
    SUM(steps)                                               AS steps,
    COUNT(*) FILTER (WHERE source = 'garmin')                AS garmin_buckets,
    COUNT(*) FILTER (WHERE source = 'phone')                 AS phone_buckets
FROM v_steps_fused_15m
GROUP BY 1
ORDER BY 1;

CREATE VIEW v_steps_daily AS
SELECT
    d.day,
    d.steps,
    g.daily_goal,
    ROUND(d.steps::numeric / g.daily_goal * 100, 1)          AS pct_goal,
    (d.steps >= g.daily_goal)                                AS goal_met,
    d.garmin_buckets,
    d.phone_buckets
FROM v_steps_daily_fused d
LEFT JOIN LATERAL (
    SELECT daily_goal
    FROM step_goal
    WHERE valid_from <= d.day
    ORDER BY valid_from DESC
    LIMIT 1
) g ON TRUE;

COMMIT;
