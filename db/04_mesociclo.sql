-- =============================================================================
--  04 — Contexto de mesociclo + resumen semanal
--  Aplicar sobre la base existente:
--    docker compose exec -T db psql -U health -d health < db/04_mesociclo.sql
-- =============================================================================
BEGIN;

-- -----------------------------------------------------------------------------
-- 1. Mesociclo: el bloque de entrenamiento vigente. Da narrativa al dashboard
--    y al informe ("Mesociclo 19 · Semana 3 de 7") y define los objetivos
--    contra los que se mide la semana (sesiones y pasos pautados por Alex).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS mesocycle (
    id                      SMALLINT     PRIMARY KEY,     -- nº de mesociclo
    objective               VARCHAR(120) NOT NULL,
    date_from               DATE         NOT NULL,
    date_to                 DATE         NOT NULL,
    steps_goal              INTEGER,                      -- NEAT pautado
    strength_sessions_week  SMALLINT,                     -- sesiones fuerza/semana
    swim_sessions_week      SMALLINT,                     -- sesiones natación/semana
    notes                   VARCHAR(255),
    created_at              TIMESTAMPTZ  NOT NULL DEFAULT now(),
    CHECK (date_from <= date_to)
);

INSERT INTO mesocycle (id, objective, date_from, date_to, steps_goal,
                       strength_sessions_week, swim_sessions_week, notes)
VALUES (19, 'Adherencia - Pérdida de grasa', DATE '2026-07-03', DATE '2026-08-16',
        9500, 3, 3, 'Full-body + natación como cardio principal; sin carrera; NEAT 9-10K')
ON CONFLICT (id) DO UPDATE SET
    objective = EXCLUDED.objective, date_from = EXCLUDED.date_from,
    date_to = EXCLUDED.date_to, steps_goal = EXCLUDED.steps_goal,
    strength_sessions_week = EXCLUDED.strength_sessions_week,
    swim_sessions_week = EXCLUDED.swim_sessions_week, notes = EXCLUDED.notes;

-- -----------------------------------------------------------------------------
-- 2. Meta de pasos del mesociclo 19 (9-10K pautados → punto medio 9.500).
--    Histórico intacto: la meta anterior sigue aplicando a días previos.
-- -----------------------------------------------------------------------------
INSERT INTO step_goal (valid_from, daily_goal, note)
VALUES (DATE '2026-07-03', 9500, 'Mesociclo 19: NEAT 9-10K pasos (sin carrera)')
ON CONFLICT (valid_from) DO UPDATE SET daily_goal = EXCLUDED.daily_goal, note = EXCLUDED.note;

-- -----------------------------------------------------------------------------
-- 3. Resumen semanal (semanas ISO, lunes a domingo, hora de Madrid):
--    la unidad de análisis real de un bloque de adherencia.
-- -----------------------------------------------------------------------------
DROP VIEW IF EXISTS v_weekly_summary;
CREATE VIEW v_weekly_summary AS
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
peso AS (
    SELECT date_trunc('week', day)::date AS week_start,
           ROUND(AVG(weight_kg), 2)      AS avg_weight
    FROM garmin_body_composition
    GROUP BY 1
),
recuperacion AS (
    SELECT date_trunc('week', COALESCE(s.day, h.day))::date AS week_start,
           ROUND(AVG(s.score))                              AS avg_sleep_score,
           ROUND(AVG(s.duration_s) / 3600.0, 1)             AS avg_sleep_h,
           ROUND(AVG(h.last_night_avg_ms))                  AS avg_hrv_ms
    FROM garmin_sleep s
    FULL OUTER JOIN garmin_hrv h USING (day)
    GROUP BY 1
)
SELECT
    p.week_start,
    p.avg_steps,
    p.days_goal_met,
    p.days_with_data,
    COALESCE(a.strength_sessions, 0)                    AS strength_sessions,
    COALESCE(a.swim_sessions, 0)                        AS swim_sessions,
    COALESCE(a.swim_m, 0)                               AS swim_m,
    COALESCE(a.total_min, 0)                            AS training_min,
    w.avg_weight,
    w.avg_weight - LAG(w.avg_weight) OVER (ORDER BY p.week_start) AS weight_delta,
    r.avg_sleep_score,
    r.avg_sleep_h,
    r.avg_hrv_ms
FROM pasos p
LEFT JOIN sesiones a     USING (week_start)
LEFT JOIN peso w         USING (week_start)
LEFT JOIN recuperacion r USING (week_start)
ORDER BY p.week_start;

COMMIT;
