# Stage 1: dependencias
FROM composer:2 AS composer

WORKDIR /app
COPY src/composer.json src/composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist

# Stage 2: runtime
FROM php:8.2-fpm-alpine

# Instalar dependencias PHP + PostgreSQL dev + bash
RUN apk add --no-cache \
    git curl zip unzip bash libpng-dev oniguruma-dev libxml2-dev postgresql-dev \
    && docker-php-ext-install pdo_pgsql pgsql mbstring bcmath pcntl gd

WORKDIR /var/www

# Copiar dependencias instaladas
COPY --from=composer /app/vendor ./vendor

# Copiar código fuente
COPY ./src .

# Permisos
RUN chown -R www-data:www-data /var/www \
    && chmod -R 755 /var/www/storage /var/www/bootstrap/cache

CMD ["php-fpm"]
