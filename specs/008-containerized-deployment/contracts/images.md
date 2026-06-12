# Contract: Image Build

The multi-stage `Dockerfile` (PHP services) and `docker/nginx/Dockerfile` (front). All bases pinned (CR-1);
secrets never enter the build (CR-2).

## PHP `Dockerfile` — stages

```
# syntax / pinned bases
ARG PHP_BASE=php:8.4.22-alpine
ARG NODE_BASE=node:22-alpine
ARG COMPOSER_BASE=composer:2.8

# --- stage: vendor (build-only) ---
FROM ${COMPOSER_BASE} AS vendor
#   add $PHPIZE_DEPS + postgresql-dev only here (build tools, NOT shipped)
#   COPY composer.json composer.lock   (lockfile BEFORE source — cache, D7)
#   RUN composer install --no-dev --prefer-dist --no-scripts --no-progress --optimize-autoloader
#   COPY . .  &&  composer dump-autoload --optimize --classmap-authoritative

# --- stage: assets (build-only) ---
FROM ${NODE_BASE} AS assets
ARG VITE_REVERB_APP_KEY            # PUBLIC client key only (R5) — no secrets as build args
#   COPY package.json package-lock.json   (lockfile BEFORE source)
#   RUN npm ci
#   COPY . .  &&  npm run build      -> public/build

# --- stage: runtime (shared final base) ---
FROM ${PHP_BASE} AS runtime
#   heaviest/most-stable first (D7): runtime libs (libpq) + compiled pdo_pgsql + opcache ini
#   create non-root user; COPY --from=vendor /app/vendor ; COPY app code ; COPY --from=assets public/build
#   COPY docker/php/entrypoint.sh /usr/local/bin/ ; ENTRYPOINT ["entrypoint.sh"]
#   USER <non-root>

# --- final targets (thin: command only) ---
FROM runtime AS fpm     # EXPOSE 9000 ; CMD ["php-fpm","-F"]            ; HEALTHCHECK php-fpm ping
FROM runtime AS queue   # CMD ["php","artisan","queue:work","--queue=default,broadcasts","--tries=1","--timeout=1320"]
FROM runtime AS reverb  # EXPOSE 8080 ; CMD ["php","artisan","reverb:start","--host=0.0.0.0","--port=8080"]
FROM runtime AS init    # CMD ["sh","-c","php artisan migrate --force && php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache && php artisan storage:link || true"]
```

**Requirements**:
- **I-1**: Build a service image with `docker build --target {fpm|queue|reverb|init}`; all four share the
  `vendor`/`assets`/`runtime` layers (built once, cached).
- **I-2**: No build stage installs application secrets; the only `ARG` carrying configuration is the **public**
  `VITE_REVERB_APP_KEY`. `docker history`/`inspect` of any shipped image MUST reveal no secret.
- **I-3**: Final images contain **no** build toolchain (`$PHPIZE_DEPS`, `postgresql-dev`, node, composer) — only
  runtime libs, the compiled extension, vendor, code, and assets.
- **I-4**: Final images run as a **non-root** user.
- **I-5**: Layer order is base → system/extensions → lockfile + dependency install → application source (D7).
- **I-6**: Each long-running target declares a `HEALTHCHECK` (R7); `init` declares none.
- **I-7**: `ENTRYPOINT` is the shared `entrypoint.sh` (the `*_FILE` shim + required-var assertion); `CMD` is the
  per-target command.

## `docker/nginx/Dockerfile` — front

```
FROM nginx:1.31-trixie-perl
#   COPY public/ (incl built public/build, from the assets stage or build context) -> /var/www/html/public
#   COPY docker/nginx/default.conf -> /etc/nginx/conf.d/
#   HEALTHCHECK: GET http://127.0.0.1/up
```

`default.conf` contract:
- serve `/var/www/html/public` static; SPA/PHP fallback to `index.php`.
- `location ~ \.php$` → `fastcgi_pass serve:9000`.
- the Reverb websocket path → `proxy_pass http://websocket:8080` with `Upgrade`/`Connection` upgrade headers
  (R4, single same-origin ingress).

## entrypoint.sh contract (`docker/php/entrypoint.sh`)

- **E-1**: For every `*_FILE` env var present, read the file, export the de-suffixed var, `unset` the `*_FILE`
  (R5). Map: `APP_KEY_FILE`, `DB_PASSWORD_FILE`, `OPENSTREET_API_KEY_FILE`, `REVERB_APP_SECRET_FILE`.
- **E-2**: Assert required vars are set and non-empty after the shim (`APP_KEY`, `DB_*`, `APP_URL`,
  `REVERB_APP_*`, `OPENSTREET_API_*`). On any missing value: print the **name** and exit non-zero (FR-015, CR-5).
- **E-3**: `exec` the container `CMD` as PID 1 (signal-correct) — never background it.
- **E-4**: Emit nothing secret to logs; log only which variable failed validation, not its value.
