# Production image for Render (or any Docker host).
# Build stage needs both PHP and Node: the Vite build runs `php artisan
# wayfinder:generate` to create the typed route helpers.

FROM serversideup/php:8.4-cli AS build

USER root

COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY package.json package-lock.json ./
RUN npm ci

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan tft:import-static \
    && npm run build \
    && rm -rf node_modules


FROM serversideup/php:8.4-fpm-nginx

# AUTORUN runs migrations and caches config/routes/views on container start.
ENV PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=true \
    LOG_CHANNEL=stderr

COPY --chown=www-data:www-data --from=build /var/www/html /var/www/html
