-- =============================================================================
--  Health App — Esquema de base de datos (PostgreSQL 15+)
--  Almacena: token de auth de Garmin (sin re-MFA), datos de Garmin
--  (proyección normalizada + blob raw), buckets de movimiento de Health Connect
--  (móvil), y vistas de fusión "Garmin si hay, móvil si no" a 15 min.
--
--  Uso mono-usuario. Para multi-cuenta, añadir account_id a las tablas de datos.
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
--  Utilidad: trigger para mantener updated_at
-- -----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;


-- =============================================================================
--  1. AUTENTICACIÓN GARMIN
--  Persiste los tokens de garth (OAuth1 durable ~1 año + OAuth2 corto
--  refrescable) para no repetir el MFA. El MFA se hace una vez (bootstrap)
--  y el sidecar solo lee/refresca a partir de aquí.
--  NOTA: datos sensibles. Restringir acceso a la fila; considerar cifrado
--  en reposo a nivel de columna/servidor.
-- =============================================================================
CREATE TABLE garmin_auth_token (
    id                  SMALLINT     PRIMARY KEY DEFAULT 1,
    account_label       VARCHAR(64)  NOT NULL UNIQUE,
    oauth1_token        TEXT         NOT NULL,   -- JSON garth (oauth_token, secret, mfa_token, expires_at)
    oauth2_token        TEXT,                    -- JSON garth (access/refresh token, expira pronto)
    mfa_completed_at    TIMESTAMPTZ,
    oauth1_expires_at   TIMESTAMPTZ,             -- ~1 año; al caducar hay que re-bootstrap
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT one_row CHECK (id = 1)            -- fuerza fila única en mono-usuario
);
CREATE TRIGGER trg_auth_updated BEFORE UPDATE ON garmin_auth_token
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- =============================================================================
--  2. ESTADO DE SINCRONIZACIÓN
--  lastSync de la app Android y última corrida del sidecar de Garmin.
-- =============================================================================
CREATE TABLE sync_state (
    key         VARCHAR(64)  PRIMARY KEY,        -- p.ej. 'hc_app_last_sync', 'garmin_sidecar_last_run'
    value_ts    TIMESTAMPTZ,
    value_text  TEXT,
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_sync_updated BEFORE UPDATE ON sync_state
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- =============================================================================
--  2b. OBJETIVO DE PASOS (propio, NO el de Garmin)
--  Con fecha de vigencia: la meta de un día = fila con el mayor valid_from
--  <= ese día. Cambiar de meta no altera el histórico. La meta de Garmin
--  (que además se auto-ajusta a diario) se ignora por completo.
-- =============================================================================
CREATE TABLE step_goal (
    valid_from  DATE         PRIMARY KEY,
    daily_goal  INTEGER      NOT NULL CHECK (daily_goal > 0),
    note        VARCHAR(255),
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- Semilla por defecto (ajústala a tu gusto)
INSERT INTO step_goal (valid_from, daily_goal, note)
VALUES (DATE '2000-01-01', 10000, 'Objetivo por defecto');


-- =============================================================================
--  3. GARMIN — RESUMEN DIARIO
--  status controla la ingesta por completitud (pending → complete). Un día
--  'complete' no se vuelve a descargar; el sidecar re-lee los últimos 2-3 días
--  (Garmin recalcula sueño/HRV hasta 24-48h después).
-- =============================================================================
CREATE TABLE garmin_daily (
    day                     DATE         PRIMARY KEY,
    steps                   INTEGER,
    distance_m              NUMERIC(10,2),
    floors                  INTEGER,
    active_kcal             INTEGER,
    bmr_kcal                INTEGER,
    resting_hr              INTEGER,
    min_hr                  INTEGER,
    max_hr                  INTEGER,
    avg_stress              INTEGER,
    intensity_min_moderate  INTEGER,
    intensity_min_vigorous  INTEGER,
    status                  VARCHAR(16)  NOT NULL DEFAULT 'pending', -- pending | complete | failed
    raw                     JSONB,
    fetched_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at              TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_garmin_daily_status ON garmin_daily (status);
CREATE TRIGGER trg_daily_updated BEFORE UPDATE ON garmin_daily
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- =============================================================================
--  4. GARMIN — SUEÑO
-- =============================================================================
CREATE TABLE garmin_sleep (
    day             DATE         PRIMARY KEY,
    duration_s      INTEGER,
    score           INTEGER,     -- puntuación propietaria (no existe en Health Connect)
    deep_s          INTEGER,
    light_s         INTEGER,
    rem_s           INTEGER,
    awake_s         INTEGER,
    hrv_avg_ms      INTEGER,     -- HRV nocturno medio
    respiration_avg NUMERIC(5,2),
    spo2_avg        NUMERIC(5,2),
    raw             JSONB,
    fetched_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_sleep_updated BEFORE UPDATE ON garmin_sleep
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- =============================================================================
--  5. GARMIN — HRV
-- =============================================================================
CREATE TABLE garmin_hrv (
    day                 DATE         PRIMARY KEY,
    last_night_avg_ms   INTEGER,
    last_night_high_ms  INTEGER,
    status              VARCHAR(24), -- balanced/unbalanced/low/poor (interpretación propietaria)
    weekly_avg_ms       INTEGER,
    baseline_low_ms     INTEGER,
    baseline_high_ms    INTEGER,
    raw                 JSONB,
    fetched_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_hrv_updated BEFORE UPDATE ON garmin_hrv
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- =============================================================================
--  6. GARMIN — VO2MAX
-- =============================================================================
CREATE TABLE garmin_vo2max (
    day               DATE         PRIMARY KEY,
    vo2max_running    NUMERIC(5,2),
    vo2max_cycling    NUMERIC(5,2),
    raw               JSONB,
    fetched_at        TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at        TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_vo2_updated BEFORE UPDATE ON garmin_vo2max
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- =============================================================================
--  7. GARMIN — COMPOSICIÓN CORPORAL (báscula Index, si la hay)
-- =============================================================================
CREATE TABLE garmin_body_composition (
    day             DATE         PRIMARY KEY,
    weight_kg       NUMERIC(5,2),
    bmi             NUMERIC(4,1),
    body_fat_pct    NUMERIC(4,1),
    muscle_mass_kg  NUMERIC(5,2),
    body_water_pct  NUMERIC(4,1),
    bone_mass_kg    NUMERIC(4,2),
    raw             JSONB,
    fetched_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_body_updated BEFORE UPDATE ON garmin_body_composition
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- =============================================================================
--  8. GARMIN — ACTIVIDADES
--  Inmutables por activity_id. La ventana rodante inserta las nuevas.
-- =============================================================================
CREATE TABLE garmin_activity (
    activity_id     BIGINT       PRIMARY KEY,
    activity_type   VARCHAR(64),
    activity_name   VARCHAR(255),
    start_time      TIMESTAMPTZ  NOT NULL,
    duration_s      NUMERIC(10,2),
    distance_m      NUMERIC(10,2),
    avg_hr          INTEGER,
    max_hr          INTEGER,
    calories        INTEGER,
    avg_speed_mps   NUMERIC(7,3),
    elevation_gain  NUMERIC(8,2),
    is_strength     BOOLEAN      NOT NULL DEFAULT FALSE,
    raw             JSONB,
    fetched_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
CREATE INDEX idx_activity_start ON garmin_activity (start_time);
CREATE INDEX idx_activity_type  ON garmin_activity (activity_type);
CREATE TRIGGER trg_activity_updated BEFORE UPDATE ON garmin_activity
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- =============================================================================
--  9. GARMIN — DETALLE DE FUERZA (series por actividad)
--  No existe en Health Connect; solo vía API de Garmin.
-- =============================================================================
CREATE TABLE garmin_strength_set (
    id              BIGSERIAL    PRIMARY KEY,
    activity_id     BIGINT       NOT NULL REFERENCES garmin_activity(activity_id) ON DELETE CASCADE,
    set_order       INTEGER      NOT NULL,
    exercise_name   VARCHAR(128),
    category        VARCHAR(64),
    reps            INTEGER,
    weight_kg       NUMERIC(6,2),
    duration_s      NUMERIC(8,2),
    set_type        VARCHAR(16),  -- ACTIVE | REST
    raw             JSONB,
    UNIQUE (activity_id, set_order)
);
CREATE INDEX idx_strength_activity ON garmin_strength_set (activity_id);


-- =============================================================================
--  10. GARMIN — PASOS INTRADÍA (buckets de 15 min)
--  Alineados a reloj (:00/:15/:30/:45). Fuente para la fusión con el móvil.
-- =============================================================================
CREATE TABLE garmin_steps_bucket (
    bucket_start    TIMESTAMPTZ  PRIMARY KEY,   -- alineado a 15 min
    bucket_end      TIMESTAMPTZ  NOT NULL,
    steps           INTEGER      NOT NULL DEFAULT 0,
    activity_level  VARCHAR(24),
    fetched_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);
-- (movido a 02_fixes.sql: el cast timestamptz::date no es IMMUTABLE)
-- CREATE INDEX idx_garmin_bucket_day ON garmin_steps_bucket ((bucket_start::date));


-- =============================================================================
--  11. HEALTH CONNECT — MOVIMIENTO DEL MÓVIL (buckets de 15 min)
--  Lo envía la app Android. origin = package que escribió el dato en HC
--  (Synthetic Package Name del contador on-device; NUNCA el de Garmin).
--  Upsert idempotente por (bucket_start, origin): reenviar el último día
--  solo refresca, no duplica.
-- =============================================================================
CREATE TABLE hc_movement_bucket (
    bucket_start    TIMESTAMPTZ  NOT NULL,       -- alineado a 15 min
    bucket_end      TIMESTAMPTZ  NOT NULL,
    origin          VARCHAR(191) NOT NULL,       -- dataOrigin.packageName
    steps           INTEGER      NOT NULL DEFAULT 0,
    distance_m      NUMERIC(10,2),
    floors          INTEGER,
    received_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (bucket_start, origin)
);
-- (movido a 02_fixes.sql)
-- CREATE INDEX idx_hc_bucket_day ON hc_movement_bucket ((bucket_start::date));


-- =============================================================================
--  VISTAS DE FUSIÓN
--  Regla: por cada bucket de 15 min, si Garmin tiene dato se usa Garmin;
--  si no, se usa el móvil. Nunca se suman ambos en el mismo bucket → sin
--  doble conteo en las horas en que llevas los dos.
-- =============================================================================
CREATE VIEW v_steps_fused_15m AS
WITH phone AS (
    SELECT bucket_start,
           SUM(steps)      AS steps,
           SUM(distance_m) AS distance_m
    FROM hc_movement_bucket
    GROUP BY bucket_start
)
SELECT
    COALESCE(g.bucket_start, p.bucket_start)              AS bucket_start,
    CASE WHEN g.bucket_start IS NOT NULL
         THEN 'garmin' ELSE 'phone' END                  AS source,
    COALESCE(g.steps, p.steps)                            AS steps
FROM garmin_steps_bucket g
FULL OUTER JOIN phone p ON p.bucket_start = g.bucket_start;

CREATE VIEW v_steps_daily_fused AS
SELECT
    bucket_start::date                                   AS day,
    SUM(steps)                                           AS steps,
    COUNT(*) FILTER (WHERE source = 'garmin')            AS garmin_buckets,
    COUNT(*) FILTER (WHERE source = 'phone')             AS phone_buckets
FROM v_steps_fused_15m
GROUP BY 1
ORDER BY 1;

-- Pasos diarios fusionados + meta efectiva (propia) + % cumplido
CREATE VIEW v_steps_daily AS
SELECT
    d.day,
    d.steps,
    g.daily_goal,
    ROUND(d.steps::numeric / g.daily_goal * 100, 1)      AS pct_goal,
    (d.steps >= g.daily_goal)                            AS goal_met,
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
