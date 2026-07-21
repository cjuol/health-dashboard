#!/usr/bin/env bash
# Aplica las migraciones pendientes de db/ contra la base de datos real del
# stack Docker (health), usando el runner `app:db:migrate`. Es el atajo para
# el flujo habitual en el VPS tras traer cambios de db/:
#
#   git pull && ./bin/migrate.sh
#
# El host no tiene PHP/Composer instalados, así que corre dentro del
# contenedor health-dashboard-web-1. Este script:
#   1. Copia el código fuente actual (src/) y los ficheros db/ al contenedor.
#   2. Limpia la caché de producción (APP_ENV=prod en el contenedor).
#   3. Ejecuta `app:db:migrate` apuntando al directorio db/ recién copiado.
#
# Los ficheros de db/ son idempotentes: la primera vez que corre este script
# sobre una base ya inicializada por docker-entrypoint-initdb.d, el runner
# reaplica TODOS los ficheros (nada estaba aún registrado en
# schema_migration) sin efecto destructivo, y a partir de ahí solo aplica
# los nuevos.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_CONTAINER="health-dashboard-web-1"

echo "==> Copiando código fuente y db/ al contenedor"
docker cp "$REPO_ROOT/symfony/src" "$WEB_CONTAINER:/var/www/html/"
docker cp "$REPO_ROOT/db" "$WEB_CONTAINER:/var/www/html/"
# docker cp deja los ficheros con el UID/GID del host; sin este chown, ni
# siquiera root dentro del contenedor puede escribir/leerlos con permisos
# ajustados (ver bin/test.sh, mismo gotcha).
docker exec "$WEB_CONTAINER" chown -R root:root /var/www/html

echo "==> Limpiando caché"
docker exec "$WEB_CONTAINER" php bin/console cache:clear --quiet

echo "==> Aplicando migraciones pendientes"
docker exec "$WEB_CONTAINER" php bin/console app:db:migrate /var/www/html/db
