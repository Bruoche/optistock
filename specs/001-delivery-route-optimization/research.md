# Research: Delivery Route Optimization — Front-End

**Date**: 2026-06-07 | **Scope**: front-end only (backend complete/verified)

## R1. Map library

- **Decision**: `maplibre-gl` + `react-map-gl` with an OSM-compatible vector style.
- **Rationale**: No API token required; vector tiles render crisply; `react-map-gl` gives a declarative React 19-friendly wrapper with `<Source>`/`<Layer>`/`<Marker>` — clean fit for click-to-add markers and a GeoJSON route line. Single map lib in the project → no competing tech.
- **Alternatives considered**:
  - *react-leaflet + leaflet* — lighter, raster OSM tiles, but raster-only and a heavier imperative bridge for layers; team chose MapLibre 2026-06-07.
  - *Mapbox GL JS* — requires account/token + has licensing terms; rejected.
  - *Google Maps* — token/billing + not OSM; rejected (spec mandates OpenStreet/OSM ecosystem).

## R2. Real-time delivery (WebSocket)

- **Decision**: `laravel-echo` + `pusher-js`, `broadcaster: 'reverb'`, subscribing to private channel `App.Models.User.{id}`, filtering by `job_uuid`; events `.TourOptimized` / `.TourOptimizationFailed`.
- **Rationale**: Backend already runs Laravel Reverb (T003) which speaks the pusher protocol; Echo is the first-party client. Channel + event names already authorized/published server-side (`routes/channels.php`).
- **Fallback**: poll `GET /api/tour/status/{job_uuid}` if WS not connected — backend already exposes this (T013). Prevents infinite spinner (spec edge cases / FR robustness).
- **Alternatives considered**: native `WebSocket`/`EventSource` — rejected, would re-implement Echo's auth handshake and channel multiplexing.

## R3. Theming / color palette (Constitution Principle VI)

- **Decision**: Re-theme the existing role CSS vars in `resources/css/app.css` (`--background`, `--foreground`, `--primary`, `--secondary`, `--accent` + their `-foreground`), add one new role var `--text-on-color` (registered in `@theme` as `--color-text-on-color`). Light + `.dark` values per the plan's Theming table.
- **Rationale**: The starter's var system already enforces Principle VI (utilities resolve to role vars; no raw hex at point of use). Changing values only — not adding a parallel `--orange-*` palette — keeps a single source of truth and lets a future re-theme be a one-place edit. Dark mode reuses the existing `use-appearance` hook (`.dark` class).
- **`text-on-color`**: distinguishes "text drawn on a primary/secondary/accent fill" (black in light, near-black `#11100F` in dark) from page `foreground` (black light / white dark). Needed because user requires black text on colored buttons even in dark mode.
- **Alternatives considered**: separate Tailwind theme/config, CSS modules, styled-components — all rejected as competing tech violating cohesion + Principle VI.

## R4. UI components

- **Decision**: Reuse shadcn `Button` (`default`=primary "Optimize/Submit", `secondary`=pale "Cancel"), `spinner.tsx` for the optimizing bar, `sonner` for failure toasts, `lucide-react` for icons, `card`/`badge` as needed.
- **Rationale**: Already installed and styled via role vars; satisfies Principle VI "no duplicated styles / reusable classes". No new UI kit.

## R5. Route line rendering (FR-019)

- **Decision**: Render the optimized path as a GeoJSON `LineString` of straight segments inside an isolated `route-layer.tsx` taking `path: {lat,lng}[]`.
- **Rationale**: Isolation boundary means swapping to road-accurate geometry (deferred `/api/route/`) changes only this component's data source — page/list untouched. Straight lines are O(n) and need no extra API call now.
- **Open question (deferred)**: `/api/route/` response shape (encoded polyline vs GeoJSON vs coord array) must be verified live before adopting road-accurate tracing — TSP schema was guessed wrong once.

## Resolved unknowns

All Technical-Context unknowns for the front-end are resolved. No remaining NEEDS CLARIFICATION.
