# Production image: PHP-FPM + nginx + supervisor in a single lightweight
# container. Built by GitHub Actions, pushed to GHCR, run on the server.

# ---- Stage 1: install PHP dependencies ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev

# ---- Stage 2: build front-end assets (Filament's welcome/auth views pull
# these in via @vite; without a manifest they 500 instead of rendering) ----
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json* ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

# ---- Stage 3: runtime ----
FROM php:8.4-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor \
        libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev libicu-dev libsqlite3-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql pdo_sqlite mbstring exif pcntl bcmath gd zip intl \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

# The official php image ships php.ini-development and php.ini-production
# templates but activates neither — without this, PHP runs on its
# compiled-in defaults, which include display_errors=On. Any warning or
# deprecation notice during a request then gets printed as raw text into
# the response body, and Laravel's own exception handler fails with
# "headers already sent" when it later tries to send its response.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && sed -i \
        -e 's/^;\?log_errors\s*=.*/log_errors = On/' \
        -e 's#^;\?error_log\s*=.*#error_log = /dev/stderr#' \
        "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build

COPY docker/prod/nginx.conf /etc/nginx/sites-enabled/default.conf
COPY docker/prod/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/prod/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
ENTRYPOINT ["entrypoint.sh"]
