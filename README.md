# Optistock

Delivery route optimization: a planner picks delivery stops on an interactive map, the app asks the
OpenStreet API for the best visit order (a closed tour), then traces the actual road path and travel
time on the map. Laravel 13 + Inertia/React back end and front end in one codebase.

## Run it locally

First time on a new machine:

```bash
composer setup     # install deps, copy .env, app key, migrate (SQLite), build assets
```

Then set your API key in `.env` (see "Configure .env" below), and start everything:

```bash
composer dev               # app server + queue workers + Vite, all at once
php artisan reverb:start    # separate terminal: WebSocket server for live results
```

Open **http://localhost:8000**, log in, go to **`/tour`**.
(Without Reverb the app still works — it falls back to polling for results, just less instant.)

## Run the tests

```bash
php artisan test    # back end (PHPUnit)
npm run test        # front end (Vitest)
composer ci:check   # everything CI runs: lint + format + types + tests
```

## Configure .env

`composer setup` copies `.env.example` → `.env`. The only value you must set by hand:

- `OPENSTREET_API_KEY=` — your OpenStreet API key (used by both the optimizer and route tracing).

Sensible defaults are already set: DB is SQLite (`database/database.sqlite`), cache/queue use the
database driver, mode is `trucking`. For live WebSocket updates also fill `REVERB_APP_*`
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
