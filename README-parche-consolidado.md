# Parche consolidado: ingesta Garmin + mesociclo (aplicar de una vez)

Une los dos parches anteriores (garmin-ingesta y mesociclo). Sustituye a
ambos: copia TODO este contenido sobre /opt/health-dashboard.

## Contenido

**Ingesta de Garmin disparada por la app:**
- sidecar/garmin_sync.py — ingesta completa (daily, buckets 15min anti-cero,
  sueño+score, HRV+estado, peso, VO2max, actividades, series de fuerza)
- sidecar/bootstrap_garmin.py — MFA una vez, tokens en garmin_auth_token
- sidecar/app.py — endpoint POST /sync-garmin (lock + cooldown 30 min)
- symfony/.../MovementApiController.php — fire-and-forget tras cada POST de la app
- docker-compose.yml — PG_DSN del sidecar, puerto 8087
- symfony/Dockerfile — fixes Apache consolidados (FallbackResource + Authorization)
- sidecar/requirements.txt — +garth, +psycopg

**Mesociclo + resumen semanal:**
- db/04_mesociclo.sql — tabla mesocycle (sembrada con el 19), meta 9.500 pasos
  desde 3/jul, vista v_weekly_summary
- symfony/.../HealthRepository.php — currentMesocycle, weeklySummary
- symfony/.../DashboardController.php — banner + semanas
- symfony/templates/dashboard/index.html.twig — banner, panel semanal, peso MA7
- symfony/.../ReportDataBuilder.php — mesociclo, semanas, ritmo natación, ⚑
- sidecar/templates/informe.html — render de todo lo anterior

## Despliegue (orden exacto)

```bash
cd /opt/health-dashboard
# 1. Copiar los archivos del zip encima (respetando rutas)

# 2. SQL del mesociclo (idempotente):
docker compose exec -T db psql -U health -d health < db/04_mesociclo.sql

# 3. Rebuild (web por el controller/templates; sidecar por garth+psycopg):
docker compose up -d --build web sidecar

# 4. Bootstrap MFA de Garmin (UNA vez, interactivo):
docker compose exec sidecar python bootstrap_garmin.py

# 5. Backfill de 30 días para igualar el histórico del móvil:
docker compose exec sidecar python garmin_sync.py 30

# 6. Verificar la fusión:
docker compose exec db psql -U health -d health -c \
  "SELECT day, steps, garmin_buckets, phone_buckets FROM v_steps_daily ORDER BY day DESC LIMIT 7;"
docker compose exec db psql -U health -d health -c \
  "SELECT * FROM v_weekly_summary ORDER BY week_start DESC LIMIT 3;"
```

Tras el paso 5, garmin_buckets deja de estar a 0 y el descuadre con Google
Health debería desaparecer. Desde entonces, cada sync de la app dispara la
ingesta automáticamente (cooldown 30 min).

## Red de seguridad (cron del host, opcional pero recomendado)

```cron
0 */4 * * * cd /opt/health-dashboard && docker compose exec -T sidecar python garmin_sync.py >> /var/log/garmin_sync.log 2>&1
```
