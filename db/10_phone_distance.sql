-- =============================================================================
--  10 — Distancia de móvil diaria.
--
--  v_steps_fused_15m ya expone phone_distance_m por bucket de 15 minutos
--  (db/02_fixes.sql, FIX 3) pero nada la leía a nivel diario. Se añade como
--  columna nueva AL FINAL de v_steps_daily_fused vía CREATE OR REPLACE VIEW:
--  las columnas existentes no cambian de nombre/tipo/orden, así que es
--  seguro (v_steps_daily, su único dependiente, selecciona columnas por
--  nombre y no necesita tocarse).
--
--  Nota: HealthRepository::dailyStepsBySource() (el consumidor PHP del
--  dashboard) calcula el agregado diario de phone_distance_m directamente
--  desde v_steps_fused_15m, no desde esta columna de v_steps_daily_fused —
--  así que esta columna no tiene consumidor PHP hoy. Se añade igualmente
--  para quien consulte a nivel SQL (psql, informes futuros, etc.): que
--  quede claro que no es "carga" para el dashboard, por si en el futuro se
--  quita o se cambia sin que nadie note un efecto visible en la app.
--
--  Se copia la definición VIGENTE de db/09_timezone.sql (con app_timezone(),
--  no el literal 'Europe/Madrid' de db/02_fixes.sql) — por eso este fichero
--  tiene que ordenar alfabéticamente después de 09.
-- =============================================================================
BEGIN;

CREATE OR REPLACE VIEW v_steps_daily_fused AS
SELECT
    (bucket_start AT TIME ZONE app_timezone())::date          AS day,
    SUM(steps)                                                 AS steps,
    COUNT(*) FILTER (WHERE source = 'garmin')                  AS garmin_buckets,
    COUNT(*) FILTER (WHERE source = 'phone')                   AS phone_buckets,
    SUM(phone_distance_m)                                      AS phone_distance_m
FROM v_steps_fused_15m
GROUP BY 1
ORDER BY 1;

COMMIT;
