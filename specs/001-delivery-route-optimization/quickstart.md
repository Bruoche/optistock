# Quickstart: Front-End (Delivery Route Optimization)

**Date**: 2026-06-07 | Assumes backend already set up (see `README.md`).

## 1. Install front-end deps

```bash
npm install maplibre-gl react-map-gl laravel-echo pusher-js
```

(Map, plus Echo client for Reverb. All other UI deps already in the starter kit.)

## 2. Front-end env (Vite)

Add to `.env` (exposed to the client via `VITE_` prefix; mirror in `.env.example`):

```
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

Echo singleton (`resources/js/lib/echo.ts`) uses `broadcaster: 'reverb'` with these.

## 3. Theme

Edit role vars in `resources/css/app.css` only (`:root` + `.dark`) per plan.md "Theming" table; add `--text-on-color` (+ `--color-text-on-color` in `@theme`). No new palette files. Dark mode toggles via existing `use-appearance` hook.

## 4. Run (four processes)

```bash
php artisan serve                        # HTTP app server → http://localhost:8000
php artisan reverb:start                 # WebSocket server → :8080
php artisan queue:work --timeout=1290    # job worker (timeout per README §4)
npm run dev                              # Vite asset/HMR server → :5173
```

Open **http://localhost:8000** (the app), not the Vite port. `npm run dev` only serves
assets — without `php artisan serve` (or Herd/Valet) nothing listens on :8000 and the
browser shows `ERR_CONNECTION_REFUSED`.

`DB_QUEUE_RETRY_AFTER=1320` must be set (see README §4) so long TSP jobs aren't retried mid-flight.

## 5. Smoke test (US1 happy path)

1. Log in (Fortify), open the tour optimize page.
2. Click ≥2 points on the map → stops appear in the list; Optimize enables.
3. Press Optimize → list greys, bottom bar shows "Optimizing…".
4. On `.TourOptimized` (or cache-hit 200): route line drawn on map, total duration shown where the button was.
5. Force a failure (stop the worker / invalid TSP key) → `sonner` error toast, list re-enables. UI never stuck.

## 6. Verify cohesion (Constitution VI)

- Grep components for raw hex (`#`) in `className`/style — should be none; only role utilities (`bg-primary`, `text-foreground`, `text-text-on-color`).
- "Cancel"-type actions use `<Button variant="secondary">`; primary actions use default `<Button>`.
