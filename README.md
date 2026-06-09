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
