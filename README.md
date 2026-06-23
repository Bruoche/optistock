# Optistock

A web application allowing to plan, optimize and assign delivery tours to help manage drivers routes efficiently.

Laravel 13 + Inertia/React back end and front end in one codebase.
Uses the OpenStreet API for itinary calculations.

## Run it locally

1. **Install** (first time on a machine):

   ```bash
   composer update
   composer setup     # install deps, copy .env, app key, migrate (SQLite), build assets
   npm install        # front-end deps (composer setup also runs this; safe to repeat)
   ```

   **Windows only — SSL certificates** 
   If getting the `cURL error 60` on request it's likely that PHP on Windows has no system CA bundle.
   Download [`cacert.pem`](https://curl.se/ca/cacert.pem) and update these two lines to your `php.ini`
   (`php --ini` shows its location):
   ```ini
   curl.cainfo = "C:/path/to/cacert.pem"
   openssl.cafile = "C:/path/to/cacert.pem"
   ```
   (Restart any running PHP processes afterwards)

2. **Add your OpenStreet API key** — REQUIRED, optimization fails without it. In `.env`:

   ```
   OPENSTREET_API_KEY=your-key-here
   ```
   (`.env.example` ships it empty. Get a key from OpenStreet. See "Configure .env" for the rest.)

3. **Start the app.** Easiest — one command runs the back-end server, queue workers, and the
   **front-end (Vite) dev server** together:

   ```bash
   composer dev               # = php artisan serve + queue workers + `npm run dev` (Vite)
   php artisan reverb:start    # separate terminal: WebSocket server for live results
   ```

   Prefer separate terminals? Run each yourself:
   ```bash
   php artisan serve                                          # back end  → :8000
   npm run dev                                                # front end → Vite/HMR
   php artisan queue:work --queue=default,broadcasts --timeout=1290   # job + broadcast workers
   php artisan reverb:start                                   # WebSocket → :8080
   ```

Open **http://localhost:8000** (the app — not Vite's port), log in, go to **`/tour`**.
(Without Reverb the app still works — it falls back to polling for results, just less instant.)

## Deploy with Docker

Production runs as a single Compose stack — nginx ingress (`front`), four PHP services
(`backend`/`queue`/`websocket`/`migrate`) and PostgreSQL — all from pinned images,
started with one command. No PHP/Node/Postgres needed on the host, just Docker + the
Compose plugin.

1. **Configure (non-secret):**
   ```bash
   cp .env.production.example .env.production   # edit APP_URL, HTTP_PORT, DB_*, REVERB_*, OPENSTREET_*
   ```

2. **Secrets (never committed — `docker/secrets/` is gitignored; `*.example` show the format):**
   ```bash
   echo "base64:$(openssl rand -base64 32)" > docker/secrets/app_key
   # (with Laravel handy: `php artisan key:generate --show` gives the same base64: format)
   printf '%s' 'a-strong-db-password' > docker/secrets/db_password      # any strong password
   printf '%s' 'your-openstreet-key'  > docker/secrets/openstreet_api_key  # the OpenStreet API key
   printf '%s' 'your-reverb-secret'   > docker/secrets/reverb_app_secret   # any strong password
   # For security on a shared linux server (skip on local deployment):
   chmod 600 docker/secrets/*
   ```

   > **Each secret file must contain exactly the value on a SINGLE line — no comments, no
   > trailing blank lines.** PostgreSQL and the PHP services read the *whole file* as the secret,
   > so a stray `#` comment or extra line becomes part of the password/key and the service fails
   > (database reports "unhealthy", optimization returns "unexpected payload"). The `*.example`
   > files are value-only on purpose: if you `cp` one, replace just that single line. Prefer the
   > `printf '%s'` form above — it writes no trailing newline.

3. **Build the images:**
   ```bash
   docker compose --env-file .env.production build
   ```
   Builds all five images from the root `Dockerfile` — the four PHP services
   (`backend`/`queue`/`websocket`/`migrate`, from targets `fpm`/`queue`/`reverb`/`init`) and the
   nginx `front` ingress (`target: web`, which reuses the same `assets` build stage as the PHP
   images so the front-end asset hashes always match). Run this first to surface any build error
   before bringing the stack up.

   To build single images directly (pass the **same** `VITE_REVERB_APP_KEY` to every build so the
   shared asset bundle is identical):
   ```bash
   docker build --target fpm -t optistock-backend .                     # the php-fpm app server
   docker build --target web --build-arg VITE_REVERB_APP_KEY=$REVERB_APP_KEY \
     -t optistock-front .                                               # the nginx ingress
   ```

4. **Start (one command):**
   ```bash
   docker compose --env-file .env.production up -d        # add --build to rebuild + start in one step
   ```
   `database` → healthy, `migrate` runs migrations and exits 0, then `backend`/`queue`/`websocket`
   start (each warms its own caches) and `front` comes up on `HTTP_PORT`.

5. **Verify:**
   ```bash
   docker compose ps                              # long-runners healthy; migrate exited (0)
   curl -fsS http://localhost:${HTTP_PORT}/up     # Laravel health route → 200
   ```
   Open `http://localhost:${HTTP_PORT}`.

6. **Teardown:**
   ```bash
   docker compose down       # keep the database volume (pgdata)
   docker compose down -v    # also DESTROY the database volume
   ```

Re-deploying elsewhere changes only `.env.production` + `docker/secrets/*` — no rebuild (the
images carry no per-environment host or secret).

## Seed the database

To seed the database:
```bash
php artisan db:seed              # populate an already-migrated DB
```

To drop the seed data and start from a clean slate:
```bash
php artisan migrate:fresh --seed # drop everything, re-migrate, then seed
```

This creates a `test@example.com` login, the delivery modes, and a set of demo drivers (varied mode sets and avatars) for manual testing. 
Seeders are idempotent (safe to re-run). The driver demo data is skipped in production.

## Run the tests

```bash
php artisan test    # back end (PHPUnit)
npm run test        # front end (Vitest)
composer ci:check   # everything CI runs: lint + format + types + tests
```

## Configure .env

`composer setup` copies `.env.example` → `.env`. Beyond `OPENSTREET_API_KEY` (step 2 above), the
defaults work out of the box: DB is SQLite (`database/database.sqlite`), cache/queue use the database
driver, travel mode is `trucking`. For live WebSocket updates also fill `REVERB_APP_*`
(`php artisan reverb:install` generates them). On Windows you may need a CA bundle for HTTPS
(`cURL error 60`) — see `specs/001-delivery-route-optimization/README.md` §1.2.

## Where to start in the code

- **Config**: `config/services.php` (`openstreet` block) + `.env`.
- **Back end** — HTTP entry points:
  - `app/Http/Controllers/TourOptimizationController.php` — optimize a tour (core feature).
  - `app/Http/Controllers/TourGeometryController.php` — road-path tracing.
  - Routes: `routes/api.php` (API) and `routes/web.php` (the page route).
  - Logic lives in `app/Services/` (e.g. `TourOptimizationService`, `TourGeometryService`,
    `OpenStreetTspClient`, `OpenStreetRouteClient`).
- **Front end**:
  - Page: `resources/js/pages/tour/optimize.tsx` — the whole screen.
  - Components: `resources/js/components/tour/`; state in `resources/js/hooks/`.
  - Theme/colors: `resources/css/app.css`.

Deeper design docs (per feature) live in `specs/` — only needed when changing architecture, not for
day-to-day work.
