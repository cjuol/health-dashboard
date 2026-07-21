-- =============================================================================
--  07 — Autenticación del dashboard y medidas corporales.
--
--  Añade la tabla de usuario de la app web (login) y una tabla de medidas
--  corporales de báscula/cinta métrica capturadas desde /perfil.
--
--  docker-entrypoint-initdb.d SOLO ejecuta los scripts de db/ en el primer
--  arranque del volumen de Postgres: en un despliegue YA INICIALIZADO (VPS
--  en producción) este fichero hay que aplicarlo A MANO:
--    docker compose exec -T db psql -U health -d health < db/07_users.sql
-- =============================================================================
BEGIN;

-- Usuario de la app web. Pensada para un único usuario en la práctica (el
-- propio Cristóbal), pero sin CHECK que fuerce una sola fila: si algún día
-- hace falta un segundo perfil (p.ej. el entrenador con su propio acceso),
-- la tabla ya lo admite sin migración.
CREATE TABLE IF NOT EXISTS app_user (
    id             SMALLSERIAL  PRIMARY KEY,
    username       TEXT         NOT NULL UNIQUE,
    password_hash  TEXT         NOT NULL,
    display_name   TEXT         NOT NULL,
    coach_name     TEXT,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- set_updated_at() ya existe (definida en 01_schema.sql): se reutiliza aquí.
DROP TRIGGER IF EXISTS trg_app_user_updated ON app_user;
CREATE TRIGGER trg_app_user_updated BEFORE UPDATE ON app_user
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Medidas corporales manuales (báscula/cinta métrica), una fila por día.
-- Todas las columnas de medida son NULLABLE: en /perfil se puede registrar
-- solo el peso un día y añadir perímetros otro día distinto sin problema.
CREATE TABLE IF NOT EXISTS body_measurement (
    day            DATE         PRIMARY KEY,
    weight_kg      NUMERIC(5,2),
    body_fat_pct   NUMERIC(4,1),
    neck_cm        NUMERIC(5,1),
    chest_cm       NUMERIC(5,1),
    waist_cm       NUMERIC(5,1),
    hip_cm         NUMERIC(5,1),
    arm_cm         NUMERIC(5,1),
    thigh_cm       NUMERIC(5,1),
    note           TEXT,
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);

DROP TRIGGER IF EXISTS trg_body_measurement_updated ON body_measurement;
CREATE TRIGGER trg_body_measurement_updated BEFORE UPDATE ON body_measurement
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMIT;
