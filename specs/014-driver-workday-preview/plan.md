# Implementation Plan: Driver Workday Preview

**Branch**: `014-driver-workday-preview` | **Date**: 2026-07-03 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/014-driver-workday-preview/spec.md`, building on
features 002 (road-accurate tracing + `RouteLayer`/`useTourGeometry`), 012 (assignment flow +
confirmation dialog) and 013 (chained workday, `TravelTimeService`, `driver_tour` start/end +
`sequence`).

## Summary

Clicking a driver stops opening the confirmation pop-up and instead draws that driver's whole
projected day on the map; a new **Assign Driver** button (right of "New tour", disabled until
a driver is selected) opens the unchanged pop-up. Mechanically:

1. **Legs ride the drivers payload.** `GET /api/tour/drivers` gains a per-driver `legs` array —
   the ordered black path pieces of the projected chain. **Connection** legs ship with their
   decoded polyline **captured from the `/route` responses `TravelTimeService` already fetches**
   for the duration math (today the polyline is discarded) — zero extra routing calls, the
   driver list gets no slower. **Prior-tour** legs ship `geometry: null` plus their ordered stop
   coordinates rotated to the recorded start/end, so the front draws a straight line instantly
   and traces lazily. Each leg carries `dotted` (connection vs tour) and `loop` (trace flag).
   The **candidate tour is not a leg** — the front already draws it (002) in the highlight color.
2. **Frontend preview.** Selection state lifts to the optimize page; a new `WorkdayLayer` draws
   the legs (neutral `--route-neutral` role color; connections dashed via `line-dasharray`;
   mounted under the candidate's `RouteLayer`). A new `useWorkdayPreview` hook renders straight
   fallbacks immediately, traces `geometry: null` legs via `POST /api/tour/geometry`
   (**no `tour_id`** → no persist side effect), applies results only if that driver is still
   selected (the proven `useTourGeometry` token pattern), and caches fetched paths per driver
   for the loaded list — rapid cycling never blocks, never mixes drivers (FR-009/FR-010).
3. **Assign flow.** Row click = toggle select; `AssignDriverDialog` moves behind the new
   button in `ResultSummary`; confirm/cancel behavior is untouched (012).

No new endpoints, no migrations, no changes to the optimization/assignment write paths.

## Technical Context

**Stack**: Laravel 12 (PHP) + React 19 + Inertia + Tailwind v4 + shadcn/ui; MapLibre GL via
react-map-gl; MySQL/SQLite; PHPUnit (`Tests\TestCase` + `RefreshDatabase`) + Vitest/Testing
Library.

**Existing pieces reused (unchanged behavior)**:
- `TravelTimeService` dedup + capped `Http::pool` (013) — same requests, now also keeping each
  response's polyline.
- `POST /api/tour/geometry` (002) — reused as-is for lazy prior-tour traces (`tour_id` omitted
  → no persistence side effect, already supported).
- `AssignDriverDialog` + `POST assign` with `start_index` (012/013) — only the trigger moves.
- `RouteLayer` runtime CSS-var color resolution — the pattern `WorkdayLayer` copies.

**Project Type**: web app (Laravel + React SPA).

**Performance/Constraints**: drivers endpoint must not get slower — guaranteed structurally
(no added routing calls; the response only carries data already in memory). Preview traces are
lazy (selected driver only), bounded (≤10 stops/leg, few prior tours), cached per list load.
Payload grows by the decoded connection polylines per driver — acceptable at the real scale
(handful of drivers, short connections).

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — every new surface covered: `OpenStreetRouteClient::legFromResponse`
  (Unit — ok/failed/invalid body); `TravelTimeService` geometry caching (Unit — coordinates
  cached with duration, null on failure/coincident, dedup unchanged); `WorkdayLegsBuilder`
  (Unit — chain order, dotted flags, connection geometry attached, loop rotation at stop *k*,
  one-way reversal, unmatched-start fallback + warning log, no-prior-tours = two connections);
  drivers endpoint (Feature, `Http::fake` — `legs` shape/order, connection geometry from the
  pooled responses, tour legs null geometry + rotated path, **route call count unchanged** vs
  013); frontend: `workday-layer` (solid vs dashed, neutral role color, renders under the
  candidate layer), `use-workday-preview` (straight-first, traces only null-geometry legs,
  **no `tour_id`**, stale response dropped after switch, cache hit on re-select),
  `driver-list` (row click selects — no dialog; toggle deselects; selected styling),
  `result-summary` (button placement, disabled ↔ selection, opens dialog). PASS.
- **II/III. Readable & Simple** — one new backend service (`WorkdayLegsBuilder`) with a single
  job (assemble legs), value objects for the shapes; response parsing consolidated into
  `legFromResponse` (shared single-call/pooled, extends 013's N2 rule); frontend follows the
  established hook/layer/page-state split (`useTourGeometry`/`RouteLayer` precedent); no
  context, no new abstraction layers. PASS.
- **IV. Robustness** — unroutable connection → `geometry: null`, straight line stays, already
  logged `warning` by `TravelTimeService`; unmatched pivot start → unrotated path + `warning`
  (never a broken preview); preview traces cannot mutate persisted tours (no `tour_id`);
  stale-response guard makes rapid cycling safe by construction; failed lazy trace keeps the
  fallback (002 precedent) while the endpoint's server-side logging records it. PASS.
- **V. Performance with Clarity** — zero added `/route` calls on the drivers endpoint (polyline
  reuse); lazy + cached + bounded preview traces; no prefetch of unviewed drivers. PASS.
- **VI. Consistent, Reusable Styling** — neutral path color is a new role variable
  `--route-neutral` defined once in `app.css` (both themes; map tiles stay light in dark mode,
  so it stays a dark neutral — same reasoning as `--primary` on the map), resolved at runtime
  like `RouteLayer.primaryColor()`; candidate keeps `--primary`; no raw hex at point of use;
  button reuses `ActionButton` with its existing disabled styling. PASS.

No violations. (Complexity Tracking omitted.)

## Decisions

Full rationale + alternatives in [research.md](research.md); condensed:

- **D1 — Legs in the drivers payload; connection geometry free.** Extend
  `GET /api/tour/drivers` with per-driver `legs`; connection polylines come from the very
  responses the duration math already fetches. Rejected: a per-driver legs endpoint (extra
  round trip per click, re-fetching, more race surface). (R1)
- **D2 — Uniform leg shape.** `{kind, dotted, path, geometry, loop}`; `path` always drawable,
  `geometry` nullable, decoded `[lat,lng]` pairs matching 002's `LegGeometry`. Front renders
  `geometry ?? path` and traces with `{stops: path, loop, mode}` — no special cases. (R2)
- **D3 — Prior tours: null geometry + server-rotated path.** Never traced server-side (would
  add `drivers × priorTours × stops` route calls). Loop entered at *k* → rotated to start at
  *k*, `loop: true`; one-way → reversed when pivot start is the last stop. Pivot↔stop match by
  `Coordinate::isSameAs`; mismatch → unrotated + `warning`. (R3)
- **D4 — Candidate tour excluded from legs.** Already drawn by `RouteLayer` in `--primary`;
  the color rule (candidate highlight vs neutral rest) falls out structurally. (R4)
- **D5 — `WorkdayLegsBuilder`.** Single-purpose assembler (warehouse, prior tours, candidate
  start/end → ordered legs); controller stays thin. "Leg" = drawable path piece (002 sense);
  the 013 travel layer keeps saying "connection". (R5)
- **D6 — `TravelTimeService` caches `{duration_s, coordinates}`.** `durationBetween` unchanged;
  new `geometryBetween`; `OpenStreetRouteClient::legFromResponse` replaces
  `durationFromResponse` (one shared parse, N2 rule). (R6)
- **D7 — Selection state in the optimize page.** Needed by map overlay + header button + list
  rows; row click toggles; cleared on reset/date change/list reload. Context rejected. (R7)
- **D8 — `useWorkdayPreview`: token-guarded lazy traces + per-load cache.** Straight fallbacks
  same-frame (SC-001); traces only the selected driver's null-geometry legs, **without
  `tour_id`**; applies results only while that driver is still selected; ref cache keyed
  `driverId:legIndex`, cleared on list reload. No prefetch, no AbortController needed. (R8)
- **D9 — `--route-neutral` role + dasharray.** New palette role (dark neutral both themes,
  map-contrast reasoning), runtime-resolved; dotted = `line-dasharray`; neutral layers under
  the candidate layer. (R9)
- **D10 — Contract evolution only.** No new routes, no request changes, no migrations. (R10)

## Project Structure (feature-specific)

Backend — **new**:
- `app/Services/WorkdayLeg.php` — value object `{kind, dotted, path, geometry, loop}` +
  array serialization for the payload.
- `app/Services/PriorTourLeg.php` — builder input `{start, end, loop, stopCoordinates}`.
- `app/Services/WorkdayLegsBuilder.php` — `build(warehouse, priorTours, candidateStart,
  candidateEnd, mode): list<WorkdayLeg>`; connection geometry/duration from
  `TravelTimeService`; tour-path rotation/reversal per D3.

Backend — **change**:
- `app/Services/OpenStreetRouteClient.php` — add non-throwing `legFromResponse(Response)`
  sharing `mapToLeg` parsing; remove `durationFromResponse` (folded in).
- `app/Services/TravelTimeService.php` — cache `{duration_s, coordinates}` per connection;
  add `geometryBetween()`; `fetchBatch` stores both from one parse.
- `app/Http/Controllers/DriverController.php` — extend the prior-assignments query set to also
  load each prior tour's `loop` + ordered stop coordinates (one grouped query, no N+1); per
  driver call `WorkdayLegsBuilder` and emit `legs` alongside the 013 fields.

Frontend — **new**:
- `resources/js/components/tour/workday-layer.tsx` — draws the legs: neutral
  `--route-neutral` (runtime-resolved like `RouteLayer`), dashed when `dotted`, solid
  otherwise; mounted before the candidate `RouteLayer`.
- `resources/js/hooks/use-workday-preview.ts` — legs of the selected driver with best-available
  geometry: immediate straight fallbacks, lazy traces (`postJson('/api/tour/geometry', {stops,
  mode, loop})`, no `tour_id`), stale-response identity guard, `driverId:legIndex` ref cache
  cleared on list reload.

Frontend — **change**:
- `resources/js/types/tour.ts` — `WorkdayLeg` type; `Driver.legs`.
- `resources/js/hooks/use-tour-drivers.ts` — map `legs` from the payload.
- `resources/js/components/tour/driver-list.tsx` — row click toggles selection (no dialog);
  selected-row styling via existing role classes; receives `selectedDriver`/`onSelect` props;
  dialog removed (moves to `result-summary`).
- `resources/js/components/tour/result-summary.tsx` — **Assign Driver** `ActionButton` right of
  "New tour", disabled without a selection; owns `AssignDriverDialog` open state; threads
  selection props to `DriverList`.
- `resources/js/pages/tour/optimize.tsx` — `selectedDriver` state; `useWorkdayPreview`;
  `<WorkdayLayer>` inside `TourMap` (before `RouteLayer`); selection cleared on reset/date
  change/list reload.
- `resources/css/app.css` — `--route-neutral` role variable (both theme blocks).

Tests: `tests/Unit/OpenStreetRouteClientTest.php` (extend — `legFromResponse`),
`tests/Unit/TravelTimeServiceTest.php` (extend — geometry cached/null, dedup unchanged),
`tests/Unit/WorkdayLegsBuilderTest.php` (new — order, flags, rotation, reversal, fallback+log,
no-prior case), `tests/Feature/DriverAvailabilityTest.php` (extend — `legs` shape/order,
connection geometry, tour legs null+rotated path, unchanged route call count);
frontend `workday-layer.test.tsx`, `use-workday-preview.test.ts` (new — straight-first,
selective tracing, no `tour_id`, stale drop, cache reuse), `driver-list.test.tsx` (rework —
selection toggle, no dialog), `result-summary.test.tsx` (extend — button states + dialog).

Out of scope (designed-for, not built): warehouse marker on the preview; multi-driver
side-by-side comparison; re-ordering a driver's day (legs are rebuilt per request, so a future
re-order feature changes only the chain source).

## Flow (select → preview → assign)

1. Presentation: `GET /api/tour/drivers` responds with the 013 fields **plus `legs`** per
   driver (connections with geometry, prior tours with `geometry: null` + rotated path).
2. Manager clicks a driver → page stores the selection → `WorkdayLayer` draws every leg
   instantly (`geometry ?? path`; dashed connections, solid tours, neutral color) around the
   still-highlighted candidate tour; **Assign Driver** enables.
3. `useWorkdayPreview` traces each `geometry: null` leg (no `tour_id`); each result replaces
   its straight line in place — unless the selection changed meanwhile (dropped) or the leg
   was already in the cache (no fetch).
4. Clicking another driver switches the preview synchronously; re-clicking the selected driver
   (or changing the date / reloading the list) clears it and disables the button.
5. **Assign Driver** → the unchanged 012 confirmation dialog (`start_index` from 013) →
   confirm records the assignment and returns to the cleared creation menu.

## API contracts (this run)

- `GET /api/tour/drivers?mode&date&tour` — response extended with per-driver `legs`
  (`kind`/`dotted`/`path`/`geometry`/`loop`, chain-ordered, candidate excluded). Request,
  validation, and all 013 fields unchanged. See `contracts/driver-workday.md`.
- `POST /api/tour/geometry` — **unchanged**; preview usage documented (no `tour_id`, stops =
  leg path, loop = leg flag) in `contracts/driver-workday.md`.

## Design Artifacts (this run)

- `research.md` — existing-slice recap + decisions R1–R10.
- `data-model.md` — no DB changes; `WorkdayLeg`/`PriorTourLeg`/builder, `TravelTimeService`
  cache widening, frontend types/state, invariants.
- `contracts/driver-workday.md` — the legs-bearing drivers payload + lazy-trace usage.
- `quickstart.md` — preview walkthrough, rapid-cycling and failure checks.

---

Generated by speckit.plan on 2026-07-03
