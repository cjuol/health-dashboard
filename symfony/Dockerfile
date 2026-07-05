FROM php:8.3-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev git unzip \
    && docker-php-ext-install pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite

# Zona horaria del intérprete: fechas y logs en hora local
RUN echo "date.timezone=Europe/Madrid" > /usr/local/etc/php/conf.d/tz.ini
ENV TZ=Europe/Madrid

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" \
        /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf

# Fixes descubiertos en despliegue: enrutar todo al front controller y
# dejar pasar la cabecera Authorization hasta PHP (si no, Bearer → 401).
RUN printf '<Directory /var/www/html/public>\n  AllowOverride All\n  FallbackResource /index.php\n</Directory>\nSetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1\n' \
        > /etc/apache2/conf-available/symfony.conf \
    && a2enconf symfony

WORKDIR /var/www/html
COPY . .
RUN composer install --no-interaction --no-dev --optimize-autoloader || true
# var/ escribible por Apache desde el build (evita el 500 post-rebuild)
RUN mkdir -p var/cache var/log var/reports && chown -R www-data:www-data var
# El "|| true" permite construir la imagen sin .env; instala en el arranque si falta.

EXPOSE 80
