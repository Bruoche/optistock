# Implementation Plan: Road-Accurate Route Tracing

**Branch**: `002-road-accurate-route-tracing` | **Date**: 2026-06-07 | **Spec**: [spec.md](spec.md)

## Summary

Replace the straight-line tour from feature 001 with the **actual road path**, as a progressive
enhancement. After the optimized result is shown (straight lines, per 001), the front-end calls a new
**synchronous** backend endpoint that fetches the road geometry for each consecutive leg from
OpenStreet `/route`, compounds the per-leg distance/duration, and returns the whole tour's geometry +
metrics in **one response**. The front-end then swaps the straight `RouteLayer` path for the road path
and updates the displayed duration/distance. Failures fall back to straight lines + the initial
estimate and are logged (never silent).

Unlike the TSP call, `/route` is **fast** — treated as a normal request, **no queue/job/WebSocket**.

## Technical Context

**Stack (reuse 001)**: Laravel 13 / PHP 8.3 backend; React 19 + Inertia + Tailwind v4 + shadcn/ui
front-end. Session-cookie auth; endpoints under `/api` (`web` middleware group).

**New backend dependency**: OpenStreet `/route` endpoint —
`GET https://maps.open-street.com/api/route/?origin=lat,lng&destination=lat,lng&mode=trucking&key=...`.
Response (per user): `{ polyline: string, total_distance: number, total_time: number, status: int }`.
Synchronous, low-latency → standard HTTP timeout, no async machinery.

**Config**: extend `config/services.php` `services.openstreet` with `route_url`
(`OPENSTREET_ROUTE_URL`, default `https://maps.open-street.com/api/route/`). Reuse existing
`key`. Add a short HTTP timeout (`OPENSTREET_ROUTE_TIMEOUT`, default ~15s) — this is a fast call.

**Mode congruence**: the `mode` MUST match between the optimization tour and the geometry trace.
Default mode is **`trucking`** everywhere (these are delivery routes). Centralise it via
`config/services.php` `services.openstreet.mode` (`OPENSTREET_MODE`, default `trucking`) so both the
TSP client (001) and the route client (002) read the same value. There is no user-facing mode
selector yet; `mode` is effectively a constant (`trucking`). Updating 001's TSP client to read this
config (it previously hard-coded `driving`) is part of this feature's centralisation.

**Front-end**: no new map library needed — `RouteLayer` already consumes a coordinate list
(001 FR-019). The backend returns **decoded coordinates** (see Decision D1), so the front feeds them
straight into `RouteLayer`. Add to the optimize flow a post-result geometry fetch.

**Unknown (must verify live before mapping — see research.md)**: the exact **encoding of `polyline`**
(Google encoded-polyline precision 5 vs 6, or another format) and the meaning of the integer `status`
codes (which value = success). The 001 TSP schema was guessed wrong once — verify against the live API.

## Constitution Check

- **I. Quality First / tests** — new client + service + endpoint covered by PHPUnit (success, per-leg
  failure, whole-tour failure, decode); front geometry-merge logic covered by vitest. PASS.
- **II/III. Readable / Simple** — one thin endpoint + one route client + a small decoder; reuse 001's
  `RouteLayer` seam. No async added. PASS.
- **IV. Robustness / no silent failure** — per-leg and whole-tour failures fall back to straight lines
  + initial estimate AND are logged with context (FR-005/006/009; constitution v1.2.0). PASS.
- **V. Performance** — single round-trip from the front (one backend response aggregating all legs);
  N upstream calls happen server-side; result does not block the optimization view (FR-007). PASS.
- **VI. Styling** — no new UI surface beyond the existing result; role CSS vars reused. PASS.

No violations.

## Decisions

- **D1 — Backend decodes polylines to coordinates.** The backend decodes each leg's `polyline` into a
  coordinate array and returns those. Rationale: `RouteLayer` already takes `{lat,lng}[]` (001 FR-019),
  so the front needs **no** new polyline-decode dependency and the boundary is honored. (User described
  "add each polyline to an array"; we return the decoded coordinates of each leg — and MAY also include
  the raw `polyline` string for debugging.) Alternative (front decodes raw polylines via a JS lib)
  rejected to keep the front thin and the `RouteLayer` interface unchanged.
- **D2 — On-demand synchronous endpoint, front-driven progressive enhancement.** The front shows
  straight lines from the 001 result, then calls `POST /api/tour/geometry`. Rationale: matches the spec's
  "draw straight first, then replace" and the fast nature of `/route`; no caching/broadcast needed for
  v1. (Optional later: cache geometry by tour hash — out of scope now.)
- **D3 — Send the ordered tour, get one aggregated response.** The endpoint receives the optimized
  ordered stops (closed tour) and returns per-leg geometry + compounded totals in a single payload, so
  the front makes exactly one request.
- **D4 — Per-leg resilience.** A failed leg yields `ok:false` + no coordinates for that leg; the front
  keeps the straight segment for it (FR-006). If every leg fails, the front keeps straight lines + the
  initial estimate (FR-005). Aggregate totals are returned only when all legs succeed; otherwise the
  road-accurate total is marked unavailable and the initial estimate stands (FR-008).

## Project Structure (feature-specific)

Backend:
- `app/Services/OpenStreetRouteClient.php` — calls `/route` for one origin→destination leg; maps
  `{polyline,total_distance,total_time,status}` → `{coordinates[], distance_m, duration_s}`; decodes
  the polyline; throws a typed failure on bad `status`/HTTP/timeout.
- `app/Services/PolylineDecoder.php` (or a method) — decode encoded polyline → `[[lat,lng],...]`.
- `app/Services/TourGeometryService.php` — iterate consecutive legs (closed tour, last→first), call the
  client per leg, compound distance/duration, assemble per-leg results; per-leg try/catch + logging.
- `app/Http/Controllers/TourGeometryController.php` (thin) + `TourGeometryRequest` (validate ordered
  coordinates) → `POST /api/tour/geometry` in `routes/api.php` (auth + a dedicated
  `throttle:tour-geometry` limiter, separate from `tour-optimize`).
- `app/Providers/AppServiceProvider.php` — bind `OpenStreetRouteClient` (config) **and** define the
  `tour-geometry` rate limiter.
- `config/services.php` — add `route_url`, `route_timeout`, `mode`.

Front-end:
- `resources/js/hooks/use-tour-geometry.ts` — **new, separate hook** (do NOT bloat 001's
  `use-tour-optimization.ts`). Given the done result (ordered stops), fetch geometry, hold
  `geometry` + composed `RoutePath` + road metrics, and expose them. Owns its own result-identity token
  (bumped on each new optimization / reset) so a superseded result's late response is ignored (FR-010).
  Does NOT key off a `job_uuid` — a 200 cache-hit result carries none. The page composes the two hooks.
- `resources/js/components/tour/route-layer.tsx` — unchanged interface; receives road coordinates when
  available, else the straight path.
- `resources/js/components/tour/result-summary.tsx` — show initial estimate, then road-accurate value
  (and handle the still-2-point-null → resolved case).
- `resources/js/types/tour.ts` — add `LegGeometry`, `TourGeometry` types.

Tests:
- `tests/Unit/OpenStreetRouteClientTest.php`, `tests/Unit/PolylineDecoderTest.php`,
  `tests/Feature/TourGeometryTest.php` (success, per-leg failure, whole-tour failure, auth/validation).
- `resources/js/hooks/use-tour-geometry.test.ts` — geometry fetch success, failure fallback, stale
  guard.

## Flow (detailed)

1. User optimizes (001) → result shown with **straight lines** + initial estimate (or "Unavailable" for
   2-point).
2. Front-end POSTs the ordered tour stops to `POST /api/tour/geometry` (one call).
3. `TourGeometryController` validates → `TourGeometryService::trace(orderedStops, mode)`.
4. Service builds consecutive legs incl. the closing leg (last→first), calls `OpenStreetRouteClient`
   per leg with `origin`, `destination`, `mode` (congruent with optimization), `key`.
5. Each leg: decode `polyline` → coordinates; read `total_distance`/`total_time`; check `status`.
   On leg failure → log + mark that leg `ok:false`, continue.
6. Service compounds `total_distance_m`/`total_duration_s` across legs; assembles per-leg array; returns
   one payload (HTTP 200).
7. Front-end receives geometry → swaps `RouteLayer` to road coordinates (per-leg: road where `ok`,
   straight where not) and replaces the displayed duration/distance with the road-accurate totals
   (or keeps the initial estimate if totals are unavailable). Stale tours ignore the response (FR-010).

## API Contract

**Our endpoint** — `POST /api/tour/geometry` (auth):
- Request: `{ "stops": [[lat,lng], ...], "mode": "driving|walking|trucking" }` (ordered; closed tour
  implied — service appends the return leg).
- Response `200`: `{ "legs": [ { "ok": true, "coordinates": [[lat,lng],...], "distance_m": int, "duration_s": int }, { "ok": false } ], "total_distance_m": int|null, "total_duration_s": int|null }`.
- `422` validation; `401` unauth.

**Upstream** — `GET {route_url}?origin=lat,lng&destination=lat,lng&mode=...&key=...` →
`{ polyline: string, total_distance: number, total_time: number, status: int }`. Success/failure keyed
off `status` (exact success code **to be verified live** — research.md).

## Error Handling & Observability

- Per-leg and whole-tour failures logged (`Log::warning`/`error`) with leg index + coordinates +
  upstream status (constitution IV). Front falls back to straight lines / initial estimate.
- Short timeout; a slow/dead `/route` host fails fast and falls back — never hangs the result view.

## Open Question (resolve in Phase 0 research)

Verify against the **live** `/route` API: (a) the `polyline` encoding (algorithm + precision) so the
decoder is correct; (b) the `status` integer code(s) that mean success vs failure; (c) the units of
`total_distance` (metres?) and `total_time` (seconds?); (d) whether multiple waypoints are supported in
one call (would let us avoid N calls).

## Design Artifacts (this run)

- `research.md` — live `/route` verification results + decode/decision rationale.
- `data-model.md` — leg/tour geometry view models + payloads.
- `contracts/tour-geometry.md` — our endpoint + upstream contract.
- `quickstart.md` — env, run, manual verification.

---

Generated by speckit.plan on 2026-06-07
