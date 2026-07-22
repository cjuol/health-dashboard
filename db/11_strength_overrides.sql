-- =============================================================================
--  11 — Overrides y enriquecimiento de series de fuerza.
--
--  sidecar/garmin_sync.py::_sync_strength_sets() hace un upsert sobre
--  garmin_strength_set con "ON CONFLICT (activity_id, set_order) DO UPDATE
--  SET" que sobrescribe TODAS las columnas de datos en cada re-sync (los
--  últimos 3 días se re-sincronizan a diario, y un re-sync manual puede
--  refrescar cualquier actividad). Cualquier edición manual del usuario que
--  se guardase directamente en garmin_strength_set desaparecería en el
--  siguiente sync. Por eso las ediciones viven en esta tabla APARTE
--  (strength_set_override) y se fusionan solo en lectura mediante la vista
--  v_strength_sets_effective — garmin_strength_set nunca se toca desde el
--  editor.
--
--  docker-entrypoint-initdb.d solo ejecuta los scripts de db/ en el primer
--  arranque del volumen de Postgres: en un despliegue YA INICIALIZADO (VPS
--  en producción) este fichero hay que aplicarlo a mano con
--  `php bin/console app:db:migrate` (o `psql -f db/11_strength_overrides.sql`).
-- =============================================================================
BEGIN;

-- Overrides por serie: solo las columnas que el usuario decide corregir
-- (NULL = sin override, se usa el valor de Garmin). Set de columnas
-- deliberadamente plano y ampliable: añadir un campo nuevo es una columna
-- nullable más, sin tocar la clave ni la vista salvo para exponerla.
CREATE TABLE IF NOT EXISTS strength_set_override (
    activity_id    BIGINT       NOT NULL REFERENCES garmin_activity(activity_id) ON DELETE CASCADE,
    set_order      INTEGER      NOT NULL,
    -- Overrides de los valores que también vienen de Garmin.
    exercise_name  VARCHAR(128),
    reps           INTEGER,
    weight_kg      NUMERIC(6,2),
    -- Enriquecimiento manual: no existe en Garmin, no hay "original" que overridear.
    rir            NUMERIC(3,1),
    rpe            NUMERIC(3,1),
    notes          TEXT,
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now(),
    PRIMARY KEY (activity_id, set_order)
);

DROP TRIGGER IF EXISTS trg_strength_set_override_updated ON strength_set_override;
CREATE TRIGGER trg_strength_set_override_updated BEFORE UPDATE ON strength_set_override
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Nota de sesión: un texto libre por actividad de fuerza (no por serie).
CREATE TABLE IF NOT EXISTS strength_session_note (
    activity_id    BIGINT       PRIMARY KEY REFERENCES garmin_activity(activity_id) ON DELETE CASCADE,
    notes          TEXT,
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT now()
);

DROP TRIGGER IF EXISTS trg_strength_session_note_updated ON strength_session_note;
CREATE TRIGGER trg_strength_session_note_updated BEFORE UPDATE ON strength_session_note
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Vista de lectura fusionada: valores efectivos (override si existe, si no
-- el de Garmin), los originales de Garmin (para mostrarlos como
-- placeholder/hint en el editor) y flags de qué columnas están editadas.
DROP VIEW IF EXISTS v_strength_sets_effective CASCADE;
CREATE VIEW v_strength_sets_effective AS
SELECT
    g.id,
    g.activity_id,
    g.set_order,
    g.category,
    g.duration_s,
    g.set_type,
    COALESCE(o.exercise_name, g.exercise_name) AS exercise_name,
    COALESCE(o.reps, g.reps)                   AS reps,
    COALESCE(o.weight_kg, g.weight_kg)         AS weight_kg,
    g.exercise_name                            AS garmin_exercise_name,
    g.reps                                     AS garmin_reps,
    g.weight_kg                                AS garmin_weight_kg,
    o.rir,
    o.rpe,
    o.notes,
    (o.exercise_name IS NOT NULL)              AS exercise_name_edited,
    (o.reps IS NOT NULL)                       AS reps_edited,
    (o.weight_kg IS NOT NULL)                  AS weight_kg_edited,
    (o.activity_id IS NOT NULL)                AS is_edited
FROM garmin_strength_set g
LEFT JOIN strength_set_override o USING (activity_id, set_order);

COMMIT;
