-- =============================================================================
--  08 — Enlaces de invitado ("compartir").
--
--  Añade la tabla de enlaces temporales que el propietario genera desde
--  /perfil/compartir para dejar que un invitado (p.ej. "Alex") vea, en modo
--  solo lectura, el dashboard/actividades/detalle recortados a un rango de
--  fechas fijo elegido al crear el enlace. El token es aleatorio (64 hex de
--  bin2hex(random_bytes(32))) y la contraseña se guarda con hash propio
--  (password_hash() nativo de PHP), sin depender del usuario de la app.
--
--  docker-entrypoint-initdb.d SOLO ejecuta los scripts de db/ en el primer
--  arranque del volumen de Postgres: en un despliegue YA INICIALIZADO (VPS
--  en producción) este fichero hay que aplicarlo A MANO:
--    docker compose exec -T db psql -U health -d health < db/08_share_links.sql
-- =============================================================================
BEGIN;

CREATE TABLE IF NOT EXISTS share_link (
    id             SMALLSERIAL  PRIMARY KEY,
    token          TEXT         NOT NULL UNIQUE,
    label          TEXT         NOT NULL,
    password_hash  TEXT         NOT NULL,
    date_from      DATE         NOT NULL,
    date_to        DATE         NOT NULL,
    expires_at     TIMESTAMPTZ  NOT NULL,
    revoked_at     TIMESTAMPTZ,
    -- Freno de fuerza bruta contado en el propio registro (no en la sesión
    -- PHP): un atacante que no reenvíe la cookie de sesión reinicia el
    -- contador en memoria, pero no puede tocar estas dos columnas.
    failed_attempts SMALLINT    NOT NULL DEFAULT 0,
    last_failed_at TIMESTAMPTZ,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CONSTRAINT share_link_date_range_chk CHECK (date_from <= date_to)
);

-- set_updated_at() ya existe (definida en 01_schema.sql): se reutiliza aquí.
CREATE TRIGGER trg_share_link_updated BEFORE UPDATE ON share_link
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

COMMIT;
