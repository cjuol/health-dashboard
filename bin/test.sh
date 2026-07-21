#!/usr/bin/env bash
# Ejecuta la suite PHPUnit contra el stack Docker ya levantado.
#
# El host no tiene PHP/Composer instalados, así que todo corre dentro del
# contenedor health-dashboard-web-1. Este script:
#   1. Recrea la base de datos health_test desde cero.
#   2. Aplica los scripts de db/ en orden (mismo esquema que producción).
#   3. Copia el código fuente actual (src, tests, config, templates,
#      phpunit.xml.dist, composer.json/.lock) al contenedor.
#   4. Instala las dependencias de desarrollo si hace falta (vendor/bin/phpunit
#      no persiste si el contenedor se reconstruye desde la imagen).
#   5. Lanza PHPUnit con APP_ENV=test y DATABASE_URL apuntando a health_test.
#
# Uso: bin/test.sh [argumentos extra para phpunit]
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DB_CONTAINER="health-dashboard-db-1"
WEB_CONTAINER="health-dashboard-web-1"
PG_USER="health"
TEST_DB="health_test"

# Token fijo usado por los tests funcionales del endpoint de movimiento.
# Debe coincidir con el valor que usan los tests en symfony/tests/.
export MOVEMENT_API_TOKEN_TEST="${MOVEMENT_API_TOKEN_TEST:-test-movement-token}"

echo "==> Leyendo credenciales de Postgres desde el contenedor de la base de datos"
POSTGRES_PASSWORD="$(docker exec "$DB_CONTAINER" env | rg '^POSTGRES_PASSWORD=' | cut -d= -f2-)"
if [ -z "$POSTGRES_PASSWORD" ]; then
    echo "No se pudo leer POSTGRES_PASSWORD del contenedor $DB_CONTAINER" >&2
    exit 1
fi

echo "==> Recreando la base de datos $TEST_DB"
# Corta conexiones abiertas de una corrida anterior antes de borrar.
docker exec "$DB_CONTAINER" psql -U "$PG_USER" -d postgres -v ON_ERROR_STOP=1 -c \
    "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '$TEST_DB' AND pid <> pg_backend_pid();" \
    >/dev/null
docker exec "$DB_CONTAINER" psql -U "$PG_USER" -d postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS $TEST_DB;"
docker exec "$DB_CONTAINER" psql -U "$PG_USER" -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE $TEST_DB;"

echo "==> Aplicando db/*.sql en orden"
for sql_file in "$REPO_ROOT"/db/*.sql; do
    echo "    - $(basename "$sql_file")"
    docker exec -i "$DB_CONTAINER" psql -U "$PG_USER" -d "$TEST_DB" -v ON_ERROR_STOP=1 < "$sql_file"
done

echo "==> Copiando código fuente al contenedor"
docker cp "$REPO_ROOT/symfony/src" "$WEB_CONTAINER:/var/www/html/"
docker cp "$REPO_ROOT/symfony/tests" "$WEB_CONTAINER:/var/www/html/"
docker cp "$REPO_ROOT/symfony/config" "$WEB_CONTAINER:/var/www/html/"
docker cp "$REPO_ROOT/symfony/templates" "$WEB_CONTAINER:/var/www/html/"
docker cp "$REPO_ROOT/symfony/phpunit.xml.dist" "$WEB_CONTAINER:/var/www/html/phpunit.xml.dist"
docker cp "$REPO_ROOT/symfony/composer.json" "$WEB_CONTAINER:/var/www/html/composer.json"
if [ -f "$REPO_ROOT/symfony/composer.lock" ]; then
    docker cp "$REPO_ROOT/symfony/composer.lock" "$WEB_CONTAINER:/var/www/html/composer.lock"
fi
# El contenedor puede quedar con archivos propiedad del usuario del host tras
# el docker cp; sin esto, composer/phpunit no pueden escribir sobre ellos.
docker exec "$WEB_CONTAINER" chown -R root:root /var/www/html

echo "==> Verificando dependencias de desarrollo"
if ! docker exec "$WEB_CONTAINER" test -x /var/www/html/vendor/bin/phpunit; then
    echo "    vendor/bin/phpunit no existe, instalando dependencias (composer install)"
    docker exec -w /var/www/html "$WEB_CONTAINER" composer install --no-interaction
fi

echo "==> Limpiando caché"
docker exec "$WEB_CONTAINER" php bin/console cache:clear --quiet || true
docker exec -e APP_ENV=test "$WEB_CONTAINER" php bin/console cache:clear --env=test --quiet || true

echo "==> Ejecutando PHPUnit"
docker exec \
    -e APP_ENV=test \
    -e DATABASE_URL="postgresql://${PG_USER}:${POSTGRES_PASSWORD}@db:5432/${TEST_DB}?serverVersion=16&charset=utf8" \
    -e MOVEMENT_API_TOKEN="$MOVEMENT_API_TOKEN_TEST" \
    -w /var/www/html \
    "$WEB_CONTAINER" vendor/bin/phpunit "$@"
