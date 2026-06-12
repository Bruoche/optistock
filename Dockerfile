# Multi-stage build for the four PHP services (serve/queue/websocket/init).
# Heavy, stable layers first; lockfiles before source so app edits don't bust the
# dependency cache (D7). Build-only images (composer, node) never ship; secrets
# never enter the build — only the PUBLIC VITE_REVERB_APP_KEY is a build arg (CR-2).

# Pinned bases (CR-1) — no `latest`/floating tags.
ARG PHP_BASE=php:8.4.22-alpine
ARG NODE_BASE=node:22-alpine
ARG COMPOSER_BASE=composer:2.8

# --- stage: vendor (build-only) -------------------------------------------------
# Composer dependencies. `--no-scripts` means no DB extension is needed here.
FROM ${COMPOSER_BASE} AS vendor
WORKDIR /app
# Lockfile before source (D7) so a code change doesn't reinstall dependencies.
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-progress --optimize-autoloader
# Now the application source, then a tight authoritative classmap.
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative

# --- stage: ext (build-only — SAME base as runtime so the ABI matches, F3) ------
FROM ${PHP_BASE} AS ext
# Build the pdo_pgsql extension against the exact runtime PHP; build deps never ship.
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps

# --- stage: assets (build-only) -------------------------------------------------
FROM ${NODE_BASE} AS assets
WORKDIR /app
# Only the PUBLIC Reverb key is a build arg (R5) — never a secret.
ARG VITE_REVERB_APP_KEY
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
# Lockfile before source (D7).
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# --- stage: runtime (shared final base) -----------------------------------------
FROM ${PHP_BASE} AS runtime
WORKDIR /var/www/html

# Heaviest/most-stable first (D7): runtime lib for pdo_pgsql (no build toolchain).
RUN apk add --no-cache libpq fcgi \
    && docker-php-ext-enable opcache

# The compiled extension from `ext` (same base → same extension dir/ABI), then enable.
COPY --from=ext /usr/local/lib/php/extensions /usr/local/lib/php/extensions
RUN docker-php-ext-enable pdo_pgsql

# Production PHP + opcache tuning.
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini

# Non-root runtime user (I-4). The stock `www-data` exists on alpine PHP images.
# Application code (with optimized autoloader) and the built assets.
COPY --chown=www-data:www-data --from=vendor /app /var/www/html
COPY --chown=www-data:www-data --from=assets /app/public/build /var/www/html/public/build

# Entrypoint shim (*_FILE → env, required-var assertion, cache warming) + healthcheck.
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php/healthcheck.sh /usr/local/bin/healthcheck.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/healthcheck.sh \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
       storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENTRYPOINT ["entrypoint.sh"]

# --- final targets (thin: command + healthcheck only) ---------------------------
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

# init: one-shot migrate only (caches are warmed per-container at startup — F2/D2).
FROM runtime AS init
CMD ["php", "artisan", "migrate", "--force"]
