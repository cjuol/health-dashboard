-- =============================================================================
--  05 — Detalle de actividades: zonas de FC y parciales/laps
--  Aplicar: docker compose exec -T db psql -U health -d health < db/05_dashboard_v2.sql
-- =============================================================================
BEGIN;

-- Tiempo en zonas de frecuencia cardiaca por actividad (Z1..Z5)
CREATE TABLE IF NOT EXISTS garmin_activity_hr_zone (
    activity_id   BIGINT    NOT NULL REFERENCES garmin_activity(activity_id) ON DELETE CASCADE,
    zone          SMALLINT  NOT NULL,          -- 1..5
    secs_in_zone  INTEGER   NOT NULL DEFAULT 0,
    low_bpm       INTEGER,                     -- límite inferior de la zona
    PRIMARY KEY (activity_id, zone)
);

-- Parciales/laps por actividad. En natación cada lap trae brazadas y SWOLF
-- (eficiencia técnica); en carrera/fuerza, distancia/tiempo/FC por parcial.
CREATE TABLE IF NOT EXISTS garmin_activity_split (
    activity_id   BIGINT       NOT NULL REFERENCES garmin_activity(activity_id) ON DELETE CASCADE,
    split_order   INTEGER      NOT NULL,
    distance_m    NUMERIC(10,2),
    duration_s    NUMERIC(10,2),
    avg_hr        INTEGER,
    max_hr        INTEGER,
    avg_speed_mps NUMERIC(7,3),
    strokes       INTEGER,                     -- natación: brazadas totales del lap
    swolf         NUMERIC(6,1),                -- natación: SWOLF medio del lap
    raw           JSONB,
    PRIMARY KEY (activity_id, split_order)
);

COMMIT;
