# Parche dashboard v2: semáforo, detalles y actividades

## Qué cambia

**Vista principal (/) — 30 días fijos, tres niveles de lectura:**
- Semáforo de recuperación arriba: cruza estado de HRV + sueño de anoche y
  recomienda (verde/ámbar/rojo) de cara a la sesión del día.
- Tarjetas con contexto y clicables: pasos hoy con barra de progreso y
  "faltan X", semana fuerza/natación vs pauta, peso en tendencia con
  δ semanal, HRV vs banda base, sueño de anoche.
- Gráficas: pasos APILADOS por fuente + objetivo (sustituye a las dos
  gráficas anteriores), sueño con FASES apiladas y ejes fijos (0-10h / 0-100),
  HRV con banda base sombreada y puntos coloreados por estado, peso con MA7,
  FC reposo, y minutos de intensidad semanales (vigorosos ×2).
- Últimas actividades con Training Effect y carga, enlazadas a su detalle.

**Vistas de detalle (/detalle/{pasos|sueno|cuerpo|carga}) — rango libre:**
- Pasos: apilado por fuente + HEATMAP día×hora coloreado por fuente
  (los turnos de cocina se pintan solos en amarillo).
- Sueño: fases + score, respiración y SpO2 nocturnos.
- Cuerpo: peso con tendencia, % grasa y masa muscular.
- Carga: FC reposo + estrés, HRV con banda, intensidad semanal.

**Actividades (/actividades y /actividad/{id}):**
- Listado filtrable por rango y tipo con TE aeróbico/anaeróbico, carga de
  entrenamiento, ritmo (natación), SWOLF y kcal.
- Detalle por sesión: tarjetas de stats, barra de TIEMPO EN ZONAS de FC,
  tabla de PARCIALES (en natación con ritmo /100m, brazadas y SWOLF por
  serie), y series de fuerza. Base del futuro "informe de actividades".

**Infra (pendientes prometidos):**
- date.timezone=Europe/Madrid horneado en la imagen PHP + TZ en compose.
- var/ con permisos www-data desde el build (adiós al 500 post-rebuild).
- Ingesta nueva en garmin_sync.py: zonas de FC y splits por actividad
  (tablas garmin_activity_hr_zone y garmin_activity_split).

## Despliegue

```bash
cd /opt/health-dashboard
# 1. copiar archivos del zip encima
docker compose exec -T db psql -U health -d health < db/05_dashboard_v2.sql
docker compose up -d --build web sidecar
# backfill del detalle de actividades del último mes:
docker compose exec sidecar python garmin_sync.py 30
```

Nota: el rango con ?desde&hasta de la portada desaparece (ahora es fija a
30 días); los rangos libres viven en Detalle y Actividades.
