# Front ingress image: nginx serving the built static assets + reverse-proxying
# PHP (FastCGI -> serve) and the websocket (-> websocket). Pinned bases (CR-1).
# Assets are built in a node stage so the image is self-contained (the build
# context excludes public/build; only the PUBLIC Reverb key is a build arg).
ARG NODE_BASE=node:22-alpine
ARG NGINX_BASE=nginx:1.31-trixie-perl
ARG COMPOSER_BASE=composer:2.8

# --- stage: wayfinder (build-only, PHP) ---
# Generate Wayfinder's TS route/action helpers where PHP exists — the node assets
# stage has none. A throwaway APP_KEY only lets the framework boot to read routes;
# no real secret enters the build (CR-2). Output: resources/js/{actions,routes,wayfinder}.
FROM ${COMPOSER_BASE} AS wayfinder
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-scripts --no-progress
COPY . .
RUN APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    php artisan wayfinder:generate --with-form

# --- stage: assets (build-only) ---
FROM ${NODE_BASE} AS assets
WORKDIR /app
ARG VITE_REVERB_APP_KEY
ENV VITE_REVERB_APP_KEY=${VITE_REVERB_APP_KEY}
# No PHP here, so skip Wayfinder's artisan call; consume the prebuilt helpers below.
ENV WAYFINDER_SKIP=1
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
# Wayfinder helpers from the PHP stage. Copied AFTER `COPY . .` so they win over any
# stale host copies; they are gitignored, so a clean clone would otherwise lack them.
COPY --from=wayfinder /app/resources/js/actions ./resources/js/actions
COPY --from=wayfinder /app/resources/js/routes ./resources/js/routes
COPY --from=wayfinder /app/resources/js/wayfinder ./resources/js/wayfinder
RUN npm run build

# --- final: nginx ---
FROM ${NGINX_BASE}
# curl backs the HEALTHCHECK below (and the compose-level check).
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# Static public assets (favicon, robots, etc.) from the context + the built bundle
# from the assets stage. PHP itself is executed by the `serve` container, not here.
COPY public/ /var/www/html/public/
COPY --from=assets /app/public/build /var/www/html/public/build
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf

# The Laravel /up health route is proxied to fpm, so a 200 means the chain is live.
HEALTHCHECK --interval=15s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1/up || exit 1
