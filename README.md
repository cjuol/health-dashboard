# Health Dashboard — Garmin + móvil (Health Connect)

App web que cierra el círculo del pipeline de salud:

```
Garmin (garmin_informe.py, tu sidecar existente) ──┐
                                                   ├──► PostgreSQL ──► Symfony (dashboard + informes)
App Android "HC Movimiento" ── POST /api/v1/... ───┘                        │
                                                                            ▼ POST /render (JSON fusionado)
                                                              Sidecar FastAPI ──► PDF para Alex
```

- **Symfony** recibe los buckets de la app Android (upsert idempotente), sirve
  el dashboard y orquesta los informes vía Messenger.
- La **fusión** "Garmin si hay dato real, móvil si no" vive en las vistas de
  PostgreSQL (`v_steps_fused_15m`, `v_steps_daily`), no en código.
- El **sidecar Python** solo renderiza: Symfony le POSTea los datos ya
  fusionados a `/render` y recibe el PDF (patrón invertido del spec).
- La **ingesta de Garmin** NO está aquí: es tu `garmin_informe.py`, que escribe
  en las tablas `garmin_*` de esta misma base de datos y actualiza
  `sync_state('garmin_sidecar_last_run')` en cada corrida.

## Puesta en marcha

1. Secretos:
   - `cp symfony/.env.example symfony/.env` y rellena `MOVEMENT_API_TOKEN`
     (el mismo `vps.token` de la app Android), `SIDECAR_TOKEN` y `APP_SECRET`.
   - Exporta `SIDECAR_TOKEN` también para compose (o crea un `.env` raíz).

2. `docker compose up -d --build`
   - En el **primer** arranque, Postgres ejecuta `db/01_schema.sql` (tu esquema
     con los dos índices rotos comentados), `db/02_fixes.sql` (correcciones:
     índices con zona fija, columna `device`, vistas de fusión anti-cero) y
     `db/03_webapp.sql` (tabla `report`).
   - Si la base ya existía, aplica a mano `02_fixes.sql` y `03_webapp.sql`.

3. Dashboard: `http://127.0.0.1:8080` (expón por Cloudflare Tunnel igual que
   el shortlink). Informes en `/informes`.

4. Apunta la app Android: `vps.baseUrl=https://tu-dominio/` → el endpoint
   completo queda `POST /api/v1/health/movement`.

## Rutas

| Ruta | Qué hace |
|---|---|
| `GET /` | Dashboard con rango de fechas (por defecto 30 días) |
| `POST /api/v1/health/movement` | Ingesta de la app Android (Bearer) |
| `GET /informes` | Listado + formulario de informes |
| `POST /informes` | Crea informe y dispara la generación |
| `GET /informes/{id}/descargar` | Descarga el PDF |
| `POST {sidecar}/render` | (interno) JSON fusionado → PDF |

## Dashboard

Seis gráficas (Chart.js) + tarjetas de resumen + últimas actividades:
pasos fusionados vs objetivo, **cobertura por fuente** (cuántos buckets de
cada día vinieron del móvil — visualiza directamente los turnos de cocina),
sueño (horas + score), HRV con banda de línea base, FC en reposo y peso.
Arriba se ve el último contacto de la app móvil y del sidecar de Garmin.

## Informe PDF

Pensado para la hoja de seguimiento de Alex: resumen del periodo (media de
pasos, días de objetivo, sueño, HRV, FC reposo, evolución de peso, sesiones
por tipo y tiempo total), gráfica de pasos vs objetivo, tabla diaria completa,
sesiones con FC y detalle de series de fuerza (ejercicio × reps × kg), y un
campo de notas libres que escribes al generarlo.

## Informes asíncronos

Por defecto Messenger usa el transporte `sync` (el PDF se genera en la misma
petición: suficiente para rangos de 2–8 semanas). Para rangos largos, cambia
el transporte en `config/packages/framework.yaml` a `doctrine://default` y
arranca un worker: `php bin/console messenger:consume -vv`.

## Desarrollo sin Docker

```bash
cd symfony && composer install
php -S 127.0.0.1:8080 -t public   # o symfony serve
cd ../sidecar && pip install -r requirements.txt
SIDECAR_TOKEN=... uvicorn app:app --port 8000
```
