-- =============================================================================
--  Tablas propias de la app web (aplicar tras schema.sql + schema-fixes.sql)
-- =============================================================================
BEGIN;

CREATE TABLE IF NOT EXISTS report (
    id          BIGSERIAL    PRIMARY KEY,
    date_from   DATE         NOT NULL,
    date_to     DATE         NOT NULL,
    notes       TEXT,
    status      VARCHAR(16)  NOT NULL DEFAULT 'pending',  -- pending | complete | failed
    pdf_path    TEXT,
    error       TEXT,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CHECK (date_from <= date_to)
);

COMMIT;
