# syntax=docker/dockerfile:1.6
#
# Standalone TypeDock profiling image.
#
# This image is intentionally separate from the production PHP-FPM image. It
# runs PHP-FPM and nginx in one disposable container and enables the SPX web UI
# for local performance analysis.

# ---------- Stage 1: Composer dependencies ----------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --optimize-autoloader \
    --ignore-platform-reqs

# ---------- Stage 2: Standalone profiling runtime ----------
FROM php:8.4-fpm-alpine AS profile

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache nginx \
    && install-php-extensions \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        curl \
        gd \
        intl \
        zip \
        opcache \
        mbstring \
        spx

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && echo "expose_php = Off" > "$PHP_INI_DIR/conf.d/zz-typedock-hardening.ini"

ENV SPX_HTTP_KEY=dev

WORKDIR /app

COPY . /app
COPY --from=vendor /app/vendor /app/vendor
COPY docker/profile/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/profile/spx.ini "$PHP_INI_DIR/conf.d/zz-typedock-spx.ini"

RUN mkdir -p \
        storage/cache \
        storage/logs \
        storage/sessions \
        storage/tmp \
        storage/uploads \
        storage/backups \
        storage/spx \
        public/uploads \
        public/themes \
        public/plugins \
        /run/nginx \
    && chown -R www-data:www-data \
        /app/storage \
        /app/public/uploads \
        /app/public/themes \
        /app/public/plugins

EXPOSE 8080

# This image is for local profiling only. nginx stays in the foreground as
# PID 1 while PHP-FPM runs as its normal master/worker process.
CMD ["sh", "-c", "php-fpm -D && exec nginx -g 'daemon off;'"]
