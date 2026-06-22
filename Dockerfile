ARG PHP_BASE=php:8.4.22-fpm-alpine
ARG NODE_BASE=node:22-alpine
ARG COMPOSER_BASE=composer:2.8
ARG NGINX_BASE=nginx:1.31-trixie-perl

# --- vendor: composer install + Wayfinder TS helper generation ------------------
FROM ${COMPOSER_BASE} AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-progress --optimize-autoloader
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative
# Wayfinder helpers must be generated where PHP exists (the assets stage has none).
# The throwaway APP_KEY only boots the framework far enough to read routes.
RUN APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    php artisan wayfinder:generate --with-form

# --- ext: compile pdo_pgsql against the SAME base as runtime so the ABI matches -
FROM ${PHP_BASE} AS ext
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql pcntl \
    && apk del .build-deps

# --- assets: vite build -------------------------------------------------------
FROM ${NODE_BASE} AS assets
WORKDIR /app
ARG VITE_REVERB_APP_KEY
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
ENV WAYFINDER_SKIP=1
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Copied after `COPY . .` so they win over stale host copies; gitignored, so a
# clean clone lacks them otherwise.
COPY --from=vendor /app/resources/js/actions ./resources/js/actions
COPY --from=vendor /app/resources/js/routes ./resources/js/routes
COPY --from=vendor /app/resources/js/wayfinder ./resources/js/wayfinder
RUN npm run build

# --- runtime: shared base for the PHP service targets ---------------------------
FROM ${PHP_BASE} AS runtime
WORKDIR /var/www/html

RUN apk add --no-cache libpq fcgi \
    && docker-php-ext-enable opcache

COPY --from=ext /usr/local/lib/php/extensions /usr/local/lib/php/extensions
RUN docker-php-ext-enable pdo_pgsql pcntl

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

COPY --chown=www-data:www-data --from=vendor /app /var/www/html
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/healthcheck.sh \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
       storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENTRYPOINT ["entrypoint.sh"]

FROM runtime AS fpm
EXPOSE 9000
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=3 \
    CMD ["/usr/local/bin/healthcheck.sh", "fpm"]
CMD ["php-fpm", "-F"]

FROM runtime AS queue
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD ["/usr/local/bin/healthcheck.sh", "queue"]
CMD ["php", "artisan", "queue:work", "--queue=default,broadcasts", "--tries=1", "--timeout=1320"]

FROM runtime AS reverb
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=5s --start-period=15s --retries=3 \
    CMD ["/usr/local/bin/healthcheck.sh", "reverb"]
CMD ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]

FROM runtime AS init
CMD ["php", "artisan", "migrate", "--force"]

# --- web: nginx ingress -------------------------------------------------------
# Reuses the `assets` stage rather than rebuilding, so the served bundle's vite
# hashes always match the PHP manifest. Reverse-proxies PHP and the websocket.
FROM ${NGINX_BASE} AS web
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*
COPY public/ /var/www/html/public/
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1
