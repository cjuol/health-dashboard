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
   - Crea un `.env` en la raíz del repo con `POSTGRES_PASSWORD` y
     `SIDECAR_TOKEN` (compose falla al arrancar si faltan). En un despliegue
     **ya existente**, cambiar `POSTGRES_PASSWORD` aquí no actualiza la
     contraseña real de Postgres: los scripts de `db/` solo corren en el
     primer arranque, así que hay que cambiarla a mano con
     `ALTER USER health WITH PASSWORD '...'` dentro del contenedor `db`.
   - `POSTGRES_PASSWORD` se interpola dentro de la URI `PG_DSN`: usa solo
     caracteres seguros para URL (alfanuméricos, `-`, `_`); `@`, `:` o `/`
     romperían la conexión del sidecar.

2. `docker compose up -d --build`
   - En el **primer** arranque, Postgres ejecuta todos los `db/*.sql` en
     orden alfabético, incluido `db/07_users.sql` (usuario del dashboard +
     medidas corporales).
   - Si la base ya existía (VPS en producción, volumen ya inicializado),
     `docker-entrypoint-initdb.d` **no** vuelve a ejecutarse: aplica a mano
     cualquier `db/*.sql` nuevo, por ejemplo:
     `docker compose exec -T db psql -U health -d health < db/07_users.sql`.

3. Crea el usuario del dashboard (una sola vez, o para cambiar la contraseña):
   `docker compose exec web php bin/console app:user:init` (sin `-T`, para
   que se asigne una TTY: la contraseña siempre se pide de forma interactiva
   y oculta, nunca como argumento). Pide usuario, contraseña —mínimo 10
   caracteres—, nombre a mostrar y nombre del entrenador. Sin usuario creado,
   `/login` siempre rechaza las credenciales.

4. Dashboard: `http://127.0.0.1:8087` (expón por Cloudflare Tunnel igual que
   el shortlink). Pide login; informes en `/informes`, perfil en `/perfil`.

5. Apunta la app Android: `vps.baseUrl=https://tu-dominio/` → el endpoint
   completo queda `POST /api/v1/health/movement` (no requiere sesión).

## Rutas

| Ruta | Qué hace |
|---|---|
| `GET /` | Dashboard con rango de fechas (por defecto 30 días) — requiere sesión |
| `GET/POST /login` | Formulario de login |
| `GET /logout` | Cierra sesión |
| `GET /perfil` | Datos del usuario, cambio de contraseña y medidas corporales |
| `POST /perfil/datos` | Actualiza nombre a mostrar y nombre del entrenador |
| `POST /perfil/password` | Cambia la contraseña (requiere la actual) |
| `POST /perfil/medidas` | Alta/actualización de una medida corporal (upsert por día) |
| `POST /perfil/medidas/{day}/eliminar` | Elimina la medida de un día |
| `POST /api/v1/health/movement` | Ingesta de la app Android (Bearer, sin sesión) |
| `GET /informes` | Listado + formulario de informes |
| `POST /informes` | Crea informe y dispara la generación |
| `GET /informes/{id}/descargar` | Descarga el PDF |
| `POST {sidecar}/render` | (interno) JSON fusionado → PDF |

Todas las rutas salvo `/login` y `/api/v1/health/movement` requieren haber
iniciado sesión (firewall `main` de `security.yaml`). El firewall `api`
(`^/api/`) tiene `security: false`: la autenticación de la app Android sigue
siendo el Bearer token que ya comprobaba `MovementApiController`, sin pasar
por el login del dashboard.

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
