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
#   no DB build tools here: `composer install --no-scripts` does not need pdo_pgsql
#   COPY composer.json composer.lock   (lockfile BEFORE source — cache, D7)
#   RUN composer install --no-dev --prefer-dist --no-scripts --no-progress --optimize-autoloader
#   COPY . .  &&  composer dump-autoload --optimize --classmap-authoritative

# --- stage: ext (build-only — SAME base as runtime so the ABI matches, F3) ---
FROM ${PHP_BASE} AS ext
#   apk add --virtual .build-deps $PHPIZE_DEPS postgresql-dev
#   docker-php-ext-install pdo_pgsql           -> pdo_pgsql.so built against php:8.4.22
#   (the .so is copied into runtime; build deps never ship)

# --- stage: assets (build-only) ---
FROM ${NODE_BASE} AS assets
ARG VITE_REVERB_APP_KEY            # PUBLIC client key only (R5) — no secrets as build args
#   COPY package.json package-lock.json   (lockfile BEFORE source)
#   RUN npm ci
#   COPY . .  &&  npm run build      -> public/build

# --- stage: runtime (shared final base) ---
FROM ${PHP_BASE} AS runtime
#   heaviest/most-stable first (D7): apk add --no-cache libpq (runtime lib only)
#   COPY --from=ext <php-ext-dir>/pdo_pgsql.so + enable it via a docker-php-ext .ini ; opcache ini
#   create non-root user; COPY --from=vendor /app/vendor ; COPY app code ; COPY --from=assets public/build
#   COPY docker/php/entrypoint.sh /usr/local/bin/ ; ENTRYPOINT ["entrypoint.sh"]
#   USER <non-root>

# --- final targets (thin: command only) ---
FROM runtime AS fpm     # EXPOSE 9000 ; CMD ["php-fpm","-F"]            ; HEALTHCHECK php-fpm ping
FROM runtime AS queue   # CMD ["php","artisan","queue:work","--queue=default,broadcasts","--tries=1","--timeout=1320"]
FROM runtime AS reverb  # EXPOSE 8080 ; CMD ["php","artisan","reverb:start","--host=0.0.0.0","--port=8080"]
FROM runtime AS init    # CMD ["php","artisan","migrate","--force"]   # migrate ONLY (writes Postgres); caches are NOT warmed here
# NOTE (F2): config/route/event/view caches and storage:link write to the LOCAL container fs. Doing them in this
# one-shot would leave the serve/queue/websocket containers (separate fs) uncached. Cache warming therefore moves
# into the shared entrypoint (E-5), run at each long-running container's startup.
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
- the Reverb websocket path **`/app`** (Pusher-protocol endpoint the Echo client connects to) →
  `proxy_pass http://websocket:8080` with `Upgrade`/`Connection: upgrade` headers + `proxy_http_version 1.1`
  (R4, single same-origin ingress). The client derives this origin from `window.location` (no baked host — F1).

## entrypoint.sh contract (`docker/php/entrypoint.sh`)

- **E-1**: For every `*_FILE` env var present, read the file, export the de-suffixed var, `unset` the `*_FILE`
  (R5). Map: `APP_KEY_FILE`, `DB_PASSWORD_FILE`, `OPENSTREET_API_KEY_FILE`, `REVERB_APP_SECRET_FILE`.
- **E-2**: Assert required vars are set and non-empty after the shim (`APP_KEY`, `DB_*`, `APP_URL`,
  `REVERB_APP_*`, `OPENSTREET_API_*`). On any missing value: print the **name** and exit non-zero (FR-015, CR-5).
- **E-3**: `exec` the container `CMD` as PID 1 (signal-correct) — never background it.
- **E-4**: Emit nothing secret to logs; log only which variable failed validation, not its value.
- **E-5** (F2): After validation and before `exec`, warm the per-container caches in the **serving** containers
  (`fpm`/`queue`/`reverb`): `config:cache`, `route:cache`, `event:cache`, `view:cache`, and ensure `storage:link`.
  This runs in each container's own filesystem (where PHP will read it), keeping caches effective and env-driven
  (no rebuild — FR-012). The `init` one-shot skips warming (it only migrates); gate on a role/CMD check or run
  warming idempotently (cheap) for all roles.
