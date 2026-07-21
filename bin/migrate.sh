#!/usr/bin/env bash
# Aplica las migraciones pendientes de db/ contra la base de datos real del
# stack Docker (health), usando el runner `app:db:migrate`. Es el atajo para
# el flujo habitual en el VPS tras traer cambios de código/db:
#
#   git pull && ./bin/migrate.sh
#
# El host no tiene PHP/Composer instalados, así que corre dentro del
# contenedor health-dashboard-web-1. Este script:
#   1. Copia el código fuente actual (src/, config/, templates/, db/ y
#      composer.json/.lock) al contenedor.
#   2. Instala las dependencias de producción (composer install --no-dev),
#      por si el commit trajo una dependencia nueva (p.ej. symfony/rate-
#      limiter) — es idempotente, no hace nada si composer.lock no cambió.
#   3. Limpia la caché de producción (APP_ENV=prod en el contenedor).
#   4. Ejecuta `app:db:migrate` apuntando al directorio db/ recién copiado.
#
# Los ficheros de db/ son idempotentes: la primera vez que corre este script
# sobre una base ya inicializada por docker-entrypoint-initdb.d, el runner
# reaplica TODOS los ficheros (nada estaba aún registrado en
# schema_migration) sin efecto destructivo, y a partir de ahí solo aplica
# los nuevos.
#
# IMPORTANTE: este script solo actualiza código PHP/config dentro de la
# imagen ya construida. Si el cambio toca el Dockerfile o la versión de PHP,
# no basta con esto — hay que reconstruir la imagen:
#   docker compose up -d --build web
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_CONTAINER="health-dashboard-web-1"

echo "==> Copiando código fuente, config y db/ al contenedor"
docker cp "$REPO_ROOT/symfony/src" "$WEB_CONTAINER:/var/www/html/"
docker cp "$REPO_ROOT/symfony/config" "$WEB_CONTAINER:/var/www/html/"
docker cp "$REPO_ROOT/symfony/templates" "$WEB_CONTAINER:/var/www/html/"
docker cp "$REPO_ROOT/symfony/composer.json" "$WEB_CONTAINER:/var/www/html/composer.json"
if [ -f "$REPO_ROOT/symfony/composer.lock" ]; then
    docker cp "$REPO_ROOT/symfony/composer.lock" "$WEB_CONTAINER:/var/www/html/composer.lock"
fi
docker cp "$REPO_ROOT/db" "$WEB_CONTAINER:/var/www/html/"
# docker cp deja los ficheros con el UID/GID del host; sin este chown, ni
# siquiera root dentro del contenedor puede escribir/leerlos con permisos
# ajustados (ver bin/test.sh, mismo gotcha).
docker exec "$WEB_CONTAINER" chown -R root:root /var/www/html

echo "==> Instalando dependencias de producción (composer install --no-dev)"
# Sin esto, un commit que añade una dependencia nueva (composer.json/.lock)
# deja el contenedor con código fuente que referencia un paquete/servicio
# ausente: el siguiente cache:clear o la siguiente petición fallan.
# Idempotente: si composer.lock no cambió, no hace nada.
docker exec -w /var/www/html "$WEB_CONTAINER" composer install --no-dev --no-interaction

echo "==> Limpiando caché"
docker exec "$WEB_CONTAINER" php bin/console cache:clear --quiet

echo "==> Aplicando migraciones pendientes"
docker exec "$WEB_CONTAINER" php bin/console app:db:migrate /var/www/html/db
