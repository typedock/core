# syntax=docker/dockerfile:1.6
#
# TypeDock PHP-FPM image (multi-stage).
# Nginx runs as a separate compose service pointing at app:9000.

# ---------- Stage 1: Composer dependencies ----------
FROM composer:2 AS vendor

WORKDIR /app

# Copy only the files needed to resolve dependencies so this layer caches well.
COPY composer.json composer.lock ./

# Drop-in plugins may have their own Composer dependencies. They are not
# installed into this base image by default; install them per plugin when
# testing locally, for example:
#   docker run --rm -u "$(id -u):$(id -g)" -v "$PWD":/app \
#     -w /app/plugins/cloud-storage composer:2 \
#     install --no-dev --prefer-dist --optimize-autoloader

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# ---------- Stage 2: Runtime (php-fpm) ----------
FROM php:8.4-fpm-alpine AS runtime

# mlocati/php-extension-installer: one-shot installer that resolves
# system dependencies automatically for each PHP extension.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN install-php-extensions \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        curl \
        gd \
        intl \
        zip \
        opcache \
        mbstring

# Production-leaning php.ini defaults.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    # Strip the `X-Powered-By: PHP/x.y.z` header. SecurityHeadersMiddleware
    # also `header_remove`s it at runtime as a belt-and-suspenders.
    && echo "expose_php = Off" > "$PHP_INI_DIR/conf.d/zz-typedock-hardening.ini"

WORKDIR /app

# Copy application source first (respect .dockerignore).
COPY . /app

# Overlay vendor/ from the composer stage.
COPY --from=vendor /app/vendor /app/vendor

# Ensure writable runtime directories exist and are owned by www-data.
# In development both the storage/ tree and public/uploads/ are overlaid
# by named volumes (see docker-compose.yml), which Docker initialises
# from this layer on first run — so ownership carries over automatically
# and UID mismatches with the host never happen.
RUN mkdir -p \
        storage/cache \
        storage/logs \
        storage/sessions \
        storage/tmp \
        storage/uploads \
        storage/backups \
        public/uploads \
        public/themes \
        public/plugins \
    && chown -R www-data:www-data \
        /app/storage \
        /app/public/uploads \
        /app/public/themes \
        /app/public/plugins

USER www-data

EXPOSE 9000

CMD ["php-fpm"]
