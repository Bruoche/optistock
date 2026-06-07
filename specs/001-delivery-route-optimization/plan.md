# Implementation Plan: Delivery Route Optimization

**Branch**: `001-delivery-route-optimization` | **Date**: 2026-06-02 | **Spec**: [spec.md](spec.md)

> **STACK-REALITY NOTE (2026-06-07)**: The original Technical Context below was written before implementation. The backend was built + verified against the actual repo (Laravel 13 / PHP 8.3 / Inertia + React / Fortify). Where this section says **Redis**, the reality is the **database** cache/queue driver; where it says **`App.Models.User.{id}`**, the reality is **`App.Models.User.{id}`**; the rate limiter lives in **`AppServiceProvider`** (no `RouteServiceProvider` in L13). See the `tasks.md` "Implementation Status" banner and the "Front-End Implementation Plan" section below for the authoritative current stack. The lines below have been corrected to match.

## Summary

Provide an asynchronous, cache-backed route-optimization flow using a Laravel backend, React (Inertia) frontend, and WebSockets. The backend never blocks on external API calls: requests return 202 with a Job UUID when work is queued; results are stored in the cache (database driver) for 24 hours and broadcast to the authenticated user's private channel `App.Models.User.{id}`.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 13)

**Frontend**: React 19 + Inertia (Laravel React Starter Kit) on Vite + Tailwind v4 + shadcn/ui

**Primary Dependencies**: Laravel queue (database driver), Laravel Echo + **Laravel Reverb** (self-hosted WebSocket server), Guzzle HTTP client, hashed cache keys (database cache store), rate limiting middleware, PHPUnit

**Storage**: Database (default connection) for app data, cache, and queue; results cached with a 24-hour TTL

**Testing**: PHPUnit for backend tests; Vitest + React Testing Library for front-end logic (state machine/components — see tasks T052–T054)

**Target Platform**: Linux or Docker-based development; Windows developer environments supported

**Performance Goals**: Respond to requests within 200ms when cache hit; queue-based processing for cache miss; support at least 10 requests/min per user (rate-limited)

**Routing API**: `https://maps.open-street.com/api/tsp/` — query params: `pts=lat,lng|lat,lng|...` (pipe-separated coordinate pairs), `nb=N` (must equal point count), `mode=driving`, `unit=m`, `tour=closed` (route returns to start), `key=OPENSTREET_API_KEY`. Base URL and key configured via `.env`.

**TSP API Response Schema** (VERIFIED against live API 2026-06-03 — earlier `status`/`route[]` guess was wrong):
```json
{
  "DIMENSION": 4,
  "TOUR": "closed",
  "COMPUTE_TIME": 0.011,
  "TOTAL_TIME": 0.145,
  "OPTIMIZATION": [0, 1, 2, 3],
  "STEPS_DURATIONS": { "TOTAL": 49261, "0": 17910, "1": 17100, "2": 8825, "3": 5426 },
  "STEPS_DISTANCES": { "TOTAL": 1143908, "0": 421122, "1": 406284, "2": 201457, "3": 115045 }
}
```
Fields:
- `OPTIMIZATION` — array of **input-coordinate indices** in optimal visit order. The API echoes **no coordinates**; the client resolves each index back to the coordinate the caller sent (`OpenStreetTspClient::mapToTour()`).
- `STEPS_DISTANCES.TOTAL` — total distance in metres; `STEPS_DURATIONS.TOTAL` — total duration in seconds (per-step values, keyed by step index, sum to `TOTAL`).
- `DIMENSION`, `TOUR`, `COMPUTE_TIME`, `TOTAL_TIME` — echo/diagnostics, unused.

Success detection: presence of an `OPTIMIZATION` array. No `status` field exists. Any payload lacking `OPTIMIZATION` → `invalid_response`; HTTP non-2xx → `api_error`; connection failure/timeout → `timeout`.

**Broadcast Payload Schema**:
- Success event (`TourOptimized`): `{ "job_uuid": "...", "data": { "ordered_stops": [{"lat": 0.0, "lng": 0.0, "order": 0}], "total_distance_m": 450000, "total_duration_s": 18000 } }`
  - **2-point tours**: `total_distance_m` and `total_duration_s` are `null` (the TSP API rejects <3 points, so `OpenStreetTspClient` short-circuits and returns the pair in order without a routing call). The front-end shows "Unavailable". Real metrics are deferred to the `/route/` integration.
- Failure event (`TourOptimizationFailed`): `{ "job_uuid": "...", "error": { "code": "api_error|timeout|invalid_response|job_failed", "message": "..." } }`

**Constraints**: External OpenStreet TSP API can be slow (minutes for large point sets) or unreliable — must be called only from background jobs. `OpenStreetTspClient` uses a split timeout: connect=15s (fail fast on dead host), read=600s (tolerate slow compute), retries=1 (exponential backoff). Timeout layers must stay ordered `read < job $timeout < worker --timeout < retry_after` (see README). API credentials stored in `.env`, never returned to clients.

**Scale/Scope**: Single-route optimization (one vehicle) per request; not multi-vehicle dispatch in v1

## Constitution Check

This plan follows the project constitution: readable code, defensive error handling, measurable performance goals, and automated tests for correctness.

## Project Structure (feature-specific)

- `app/Http/Controllers/TourOptimizationController.php` (thin — HTTP translation only)
- `app/Services/TourOptimizationService.php` (orchestrates the request flow)
- `app/Services/TourOptimizationResult.php` (ready-or-pending result DTO)
- `app/Jobs/OptimizeTourJob.php`
- `app/Services/CoordinateNormalizer.php`
- `app/Services/OpenStreetTspClient.php`
- `app/Services/TourCache.php`
- `routes/api.php` (POST `/api/tour/optimize`, GET `/api/tour/status/{job_uuid}`)
- Front-end (see "Front-End Implementation Plan" for detail): `resources/js/pages/tour/optimize.tsx`, `resources/js/components/tour/{tour-map,route-layer,stop-list,optimizing-bar,result-summary}.tsx`, `resources/js/hooks/use-tour-optimization.ts`, `resources/js/lib/echo.ts`, `resources/js/types/tour.ts`. (NOT `resources/js/routes/` — that dir is Wayfinder-generated.)
- `tests/Feature/TourOptimizationTest.php`
- `tests/Feature/TourOptimizationBroadcastTest.php`
- `tests/Unit/CoordinateNormalizerTest.php`

## Flow (detailed)

1. Frontend sends POST `/api/tour/optimize` with array of `[lat, lng]` coordinate pairs and user auth token. Coordinates are entered directly by the user; geocoding is out of scope.
2. Controller validates the request and hands off to `TourOptimizationService::optimize()`; everything below runs in the service.
3. Service calls `CoordinateNormalizer::normalize()` (round + stable-sort) then sha256-hashes that canonical list into the cache key: `tour:{hash}`.
4. Service checks the cache; if hit, it returns a "ready" result and the controller responds 200 with the cached data.
5. If miss, the service generates a Job UUID, dedups via the active-job lock (reusing a running job if any), `OptimizeTourJob::dispatch()`es with UUID/user ID/canonical payload, and marks status 'pending' (short TTL).
6. Service returns a "pending" result; the controller responds HTTP 202 with `job_uuid` immediately.
7. `OptimizeTourJob` calls `OpenStreetTspClient` using credentials from `.env`; on success store full result in the cache (database store) with 24-hour TTL and publish broadcast event on channel `App.Models.User.{id}` with payload `{ job_uuid, data }`.
8. On failure, job stores an error record and broadcasts a failure event `{ job_uuid, error }` to `App.Models.User.{id}`.
9. Frontend subscribes to `App.Models.User.{id}` and filters events by `job_uuid`; also shows immediate 202 UI and optionally poll for cache result (long-poll or GET `/api/tour/status/{job_uuid}`) if WS unavailable.

## Security & Rate Limiting

- Use Laravel BroadcastAuth to ensure `App.Models.User.{id}` channels require auth and match current user ID.
- Rate limit POST `/api/tour/optimize` to 10/min per authenticated user via `throttle:tour-optimize`, a named limiter defined in `AppServiceProvider` (Laravel 13 has no `RouteServiceProvider`).
- Validate inputs strictly (coordinate arrays, min 2 points, max N points — default N=10).

## Error Handling & Observability

- On every external API call, catch timeouts, HTTP errors, and unexpected responses; map them to structured `error_code` and `message` for broadcasts.
- Log job execution outcomes, durations, and API latencies to application logs and, if available, tracing (e.g., Sentry, OpenTelemetry).
- Broadcast failures to prevent infinite loading on frontend.

## Environment & Configuration

- `.env` entries: `OPENSTREET_API_URL`, `OPENSTREET_API_KEY`, `OPENSTREET_API_TIMEOUT`, `OPENSTREET_API_RETRIES`, `BROADCAST_CONNECTION=reverb`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`, `DB_QUEUE_RETRY_AFTER=1320`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT`, `REVERB_SCHEME`. (Front-end also needs `VITE_REVERB_*` — see quickstart.md.)
- Cache key prefixes (database cache store): `tour:{hash}` for results; `tour:status:{job_uuid}` for pending markers.

## Tasks (high-level)

- Implement API endpoint and validation
- Implement `CoordinateNormalizer` and hashing
- Add cache checks and TTL (database cache store)
- Create `OptimizeTourJob` background worker
- Implement `OpenStreetTspClient` with robust retries and timeouts
- Integrate broadcasting on job success/failure
- Add frontend components and WS handling
- Add rate limiting and request quotas
- Add tests (unit and feature) and README

## Acceptance Criteria

- Cache hit returns result within 200ms
- Cache miss returns HTTP 202 and job UUID immediately
- Successful jobs result in cache storage (database store) with 24h TTL and WS broadcast
- Failure jobs broadcast an error event so frontend stops waiting
- Rate limiter enforces 10 per minute per user

## Next Steps

1. Implement `CoordinateNormalizer` and unit tests
2. Add API endpoint and cache lookup
3. Implement job and external client
4. Implement frontend hooks and broadcasting

---

## Front-End Implementation Plan (added 2026-06-07)

> Backend is complete + verified (see `tasks.md` status banner). This section plans the **front-end only** (tasks T016, T020 + theming). It supersedes the older "add frontend components" bullets above where they conflict.

### Stack reality (verified against repo)

The repo is the **Laravel React Starter Kit + shadcn/ui on Tailwind v4** — not the "React Starter Kit / Jest" guessed in the original Technical Context. Front-end MUST reuse this; no competing styling/UI tech.

- **Tailwind v4** via `@import 'tailwindcss'` + `@theme` block in `resources/css/app.css`. Colors already role-based CSS vars (`--primary`, `--secondary`, `--accent`, `--background`, `--foreground`, `--muted`, `--destructive`) exposed as `bg-primary`, `text-foreground`, etc. Light + `.dark` themes; theme switching via existing `use-appearance` hook (`.dark` class + cookie/localStorage).
- **shadcn/ui** components in `resources/js/components/ui/` built with `cva` + `cn` (clsx + tailwind-merge). Already present and reused: `button.tsx` (variants `default`/`secondary`/`destructive`/`outline`/`ghost`), `spinner.tsx`, `card.tsx`, `badge.tsx`, `sonner.tsx` (toasts), `icon.tsx`. Icons via `lucide-react`.
- **Inertia + React 19** (`@inertiajs/react`). Pages live in `resources/js/pages/` (NOT `resources/js/routes/` — Wayfinder-generated, nor `resources/js/wayfinder/`).

### New dependencies (additive — no conflict with anything installed)

| Package | Purpose | Why this one |
|---------|---------|--------------|
| `maplibre-gl` + `react-map-gl` | Interactive map, markers, route line | Vector tiles, no API token required with OSM-compatible style; React 19-compatible wrapper. Chosen over Leaflet per decision 2026-06-07. |
| `laravel-echo` + `pusher-js` | Subscribe to Reverb private channel | Reverb speaks the pusher protocol; Echo is the first-party Laravel client. Backend Reverb already installed (T003). |

No other UI/styling/map/WS libraries are added. Reuse `spinner.tsx`, `sonner`, `lucide-react` for loading/error/icons.

### Theming (Constitution Principle VI — re-theme existing vars, do NOT add a parallel palette)

Edit the role vars in `resources/css/app.css`. The starter's var system already satisfies Principle VI (no raw hex at point of use); we change values only, plus add one role var for "text on a colored background".

**`:root` (light):**
- `--background: #FFFFFF`
- `--foreground: #000000`
- `--primary: #FF9A3C`, `--primary-foreground: #000000`
- `--secondary: #FFCF8C`, `--secondary-foreground: #000000`
- `--accent: #FFC802`, `--accent-foreground: #000000`
- new `--text-on-color: #000000`

**`.dark`:**
- `--background: #11100F`
- `--foreground: #FFFFFF`
- `--primary: #F99435`, `--primary-foreground: var(--text-on-color)`
- `--secondary: #FFCF8C`, `--secondary-foreground: var(--text-on-color)`
- `--accent: #FFC802`, `--accent-foreground: var(--text-on-color)`
- new `--text-on-color: #11100F`

Register the new role in the `@theme` block: `--color-text-on-color: var(--text-on-color);` → usable as `text-text-on-color`. Existing colors are oklch in the starter; new values may be written as hex (Tailwind v4 accepts hex var values) — keep one notation consistent per the team's preference. **No off-palette literals** anywhere in components (Principle VI): components use `bg-primary`, `text-foreground`, `text-text-on-color`, etc.

Button mapping: "Optimize"/"Submit" = `<Button>` (default = primary). "Cancel" = `<Button variant="secondary">` (pale orange).

### Component / file layout (`resources/js/`)

- `pages/tour/optimize.tsx` — the screen. Layout: map top ~2/3 (`h-[66vh]`/grid), lower third = stop list + Optimize button on top of it; bottom-anchored loading bar. Owns the optimize flow state machine.
- `components/tour/tour-map.tsx` — wraps `react-map-gl` Map: OSM style, click-to-add-stop, renders numbered stop markers.
- `components/tour/route-layer.tsx` — **FR-019 isolation boundary.** Props: `path: Array<{lat,lng}>`. Renders straight-line segments (GeoJSON `LineString` Source+Layer). Road-accurate tracing later = change only this file's data source. Page/list never touch geometry.
- `components/tour/stop-list.tsx` — list of stops beneath map; per-row remove; greyed/disabled while optimizing (`aria-disabled`, `pointer-events-none opacity-50`); button slot on top.
- `components/tour/result-summary.tsx` — replaces the button row after result; shows total duration (formatted from `total_duration_s`). Freed list space left empty (reserved future drivers list).
- `components/tour/optimizing-bar.tsx` — bottom horizontal bar, `spinner.tsx` + "Optimizing…".
- `hooks/use-tour-optimization.ts` — state machine `idle → submitting → pending → done | failed`; POST `/api/tour/optimize`; on 200 render immediately; on 202 subscribe Echo private `App.Models.User.{id}`, filter by `job_uuid`, listen `.TourOptimized` / `.TourOptimizationFailed`; WS-fallback poll `GET /api/tour/status/{job_uuid}`; unsubscribe on terminal state; failures → `sonner` toast.
- `lib/echo.ts` — Echo singleton (Reverb config from Vite env `VITE_REVERB_*`), `broadcaster: 'reverb'`.

### State machine (front-end)

`idle` → (≥2 stops, click Optimize) → `submitting` (POST) → 200 ⇒ `done` (render from body) | 202 ⇒ `pending` (grey list, show bar, subscribe) → `.TourOptimized`|status=done ⇒ `done` | `.TourOptimizationFailed`|status=failed ⇒ `failed` (toast, re-enable list). `done`/`failed` → "new optimization" resets to `idle` (FR-008).

### Constitution Check (re-evaluated)

- **I/II/III** — reuse starter components + single page state machine; small single-responsibility components. PASS.
- **IV (robustness)** — WS-fallback polling + `failed()` broadcast + toast prevent infinite spinner (spec edge cases). Disabled list prevents mid-flight edits. PASS.
- **V (performance)** — 200 cache-hit renders without WS round-trip; straight-line render is O(n). PASS.
- **VI (styling)** — re-theme role vars, no parallel palette, no off-palette literals; reuse `Button`/`spinner`/`sonner`; `RouteLayer` shared boundary. PASS.

No violations to justify.

### Out of scope (deferred — see spec "Deferred / Future Enhancements")

Road-accurate route tracing (`/api/route/`), address geocoding/search input, drivers list.

### Design artifacts (this run)

- `research.md` — map lib, Echo/Reverb, theming decisions + alternatives.
- `data-model.md` — front-end view models / state shapes.
- `contracts/frontend-ui.md` — UI ↔ backend contract (existing HTTP + WS endpoints the front-end consumes).
- `quickstart.md` — install deps, env, run (vite + reverb + queue worker).

---

Generated by speckit.plan on 2026-06-02 (front-end section added 2026-06-07)
