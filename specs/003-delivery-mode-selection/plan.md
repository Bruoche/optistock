# Implementation Plan: Delivery Mode Selection

**Branch**: `003-delivery-mode-selection` | **Date**: 2026-06-08 | **Spec**: [spec.md](spec.md)

## Summary

Expose the travel `mode` — fixed to `trucking` in config since 002 — as a user choice
(`trucking` default, `driving`, `walking`) via a dropdown placed to the left of the "Optimize route"
button beneath the map. The chosen mode drives **both** the tour optimization (001, async job) **and**
the road-geometry trace (002, synchronous). The trace flow already accepts `mode` end-to-end; the
optimize flow does not — and its cache currently keys on coordinates alone, so a different mode would
wrongly hit a previous mode's cached tour. The bulk of this feature is threading `mode` through the
optimize request → service → **cache key** → job → TSP client, and surfacing the dropdown on the front,
snapshotting the mode with each result so the displayed tour's geometry always matches the mode it was
optimized with (FR-007).

## Technical Context

**Stack (reuse 001/002)**: Laravel 13 / PHP 8.3 backend; React 19 + Inertia + Tailwind v4 + shadcn/ui
front-end. Session-cookie auth; `/api` endpoints under the `web` group. Cache-backed async optimization
job + Reverb broadcast (001); synchronous `/route` trace (002).

**Allowed modes**: `trucking` (default), `driving`, `walking`. These three are **already** the accepted
set on the trace endpoint (`TourGeometryRequest`: `in:driving,walking,trucking`) and the upstream API
accepts them (002 wired `mode` to `/route` live). No new live-API verification needed — see research.md.

**Current state (what exists vs. what's missing)**:
- Trace flow (002) — **complete**: `TourGeometryRequest` validates `mode` (optional), `TourGeometryController`
  passes `validated('mode') ?? config(...)`, `TourGeometryService::trace($stops, $mode)` and
  `OpenStreetRouteClient::traceLeg(..., $mode)` honor it. Only gap: the front never **sends** `mode`.
- Optimize flow (001) — **not mode-aware**: `OptimizeTourRequest` has no `mode`;
  `TourOptimizationService::optimize($userId, $coordinates)`, `OptimizeTourJob`, and
  `OpenStreetTspClient::optimize($coordinates)` carry no mode (the client reads the config constant);
  `TourCache` keys (`tour:{hash}`, `tour:active:{userId}:{hash}`) ignore mode.

**Config**: `services.openstreet.mode` (default `trucking`) is **kept** as the fallback when a request
omits `mode`. It is no longer the sole source — the request value wins.

**Front-end**: a shadcn `Select` already exists (`components/ui/select.tsx`); the dropdown reuses it.
Mode state lives on the optimize page; it is passed into `optimize(mode)` and snapshotted into the
`done` state so geometry for the shown tour uses that tour's mode (not the live dropdown — FR-008).

**Project Type**: web application (Laravel API + React/Inertia SPA).

**Performance/Scale**: unchanged from 001/002. Adding `mode` to the cache key multiplies cache entries
by at most 3 (one per mode) — negligible.

## Constitution Check

*GATE: re-checked after design below.*

- **I. Quality First / tests** — new/changed behavior covered: optimize request mode validation + mode
  threading to the job/client, cache **mode separation** (same coords, different mode ⇒ distinct entry,
  no cross-mode hit), front hooks send mode, `ModeSelect` renders/defaults trucking. PASS.
- **II/III. Readable & Simple** — `mode` is one explicit string parameter threaded along the existing
  seams; the allowed set is centralized once in a `DeliveryMode` enum (no duplicated `in:` lists). One
  small new component (`ModeSelect`) + one control-bar layout tweak. No new async machinery. PASS.
- **IV. Robustness / no silent failure** — an out-of-set mode is rejected at validation (422); an omitted
  mode falls back to the config default; geometry per-leg/whole-tour failures keep 002's logged
  fallback. Mode↔geometry congruence (FR-007) is guaranteed by snapshotting, not by trusting live UI
  state. PASS.
- **V. Performance with Clarity** — no extra round-trips; mode rides existing requests. Cache key gains
  one short segment. PASS.
- **VI. Consistent, Reusable Styling** — dropdown reuses the shared `Select` component and role-named
  color variables; no raw hex, no duplicated visual rules. PASS.

No violations. (Re-evaluated post-design: still no violations.)

## Decisions

- **D1 — `mode` is an explicit string parameter, fed to both requests, the job, and the cache.** Per the
  user's framing: add a `mode` field to `OptimizeTourRequest` (mirroring `TourGeometryRequest`), thread
  it through `TourOptimizationService::optimize($userId, $coordinates, $mode)` → `OptimizeTourJob` (new
  readonly `$mode`) → `OpenStreetTspClient::optimize($coordinates, ?string $mode = null)` (override,
  mirroring the route client). An omitted mode falls back to `config('services.openstreet.mode')` at the
  controller, so existing callers/tests keep working.

- **D2 — The tour cache key includes the mode.** A tour optimized for `walking` differs from `trucking`,
  so the cache MUST NOT serve one for the other. Thread `mode` into the `TourCache` key builders so keys
  become `tour:{mode}:{hash}` and `tour:active:{userId}:{mode}:{hash}` (status key stays keyed on
  `jobUuid`). `coordinatesHash` keeps its meaning (a pure function of coordinates); mode is a separate,
  explicit cache dimension — matching the user's "the job cache should contain and expect this field".
  *Alternative rejected*: folding mode into the sha256 input — hides the dimension and muddies the
  `coordinatesHash` name (naming-philosophy: names must be self-evident).

- **D3 — The allowed mode set is centralized in one `DeliveryMode` enum.** `App\Enums\DeliveryMode`
  (string-backed: `Trucking='trucking'`, `Driving='driving'`, `Walking='walking'`) is the single source
  for the validation rule in **both** form requests (`Rule::enum(DeliveryMode::class)`) — removing the
  duplicated `in:driving,walking,trucking` literal — and for the default. The front mirrors it with a TS
  union + a small ordered options array for the select. *Rationale*: constitution III / "eliminate
  duplicate logic"; domain-noun naming (naming-philosophy).

- **D4 — Mode is snapshotted with the result; the live dropdown does not retro-edit a shown tour
  (FR-008/FR-007).** `optimize(mode)` records the mode into the optimization state; the `done` state
  carries `mode`. `useTourGeometry(result, mode)` sends that snapshotted mode, so the polyline always
  matches the optimization mode even if the planner changes the dropdown afterward.

- **D5 — The dropdown sits in a control bar with the Optimize button, in the editing view.** The
  "Optimize route" button moves out of `StopList` into a small flex bar directly beneath the map:
  `[ ModeSelect (left) ] [ Optimize route ]`. `StopList` becomes the list only. The bar belongs to the
  editing state (where validation happens); when a result is shown, `ResultSummary` + reset take over,
  and a new mode can be chosen after reset. Matches spec FR-001/SC-001 ("left of the validation button").

## Project Structure (feature-specific)

Backend — **change**:
- `app/Enums/DeliveryMode.php` — **new** string-backed enum (the allowed set + a `default()` helper).
- `app/Http/Requests/OptimizeTourRequest.php` — add `mode` rule (`sometimes`, enum) + message.
- `app/Http/Requests/TourGeometryRequest.php` — swap the literal `in:` list for `Rule::enum(DeliveryMode::class)`.
- `app/Http/Controllers/TourOptimizationController.php` — read `validated('mode') ?? config('services.openstreet.mode')`, pass to the service.
- `app/Http/Controllers/TourGeometryController.php` — unchanged (already passes mode); optionally reuse the enum default.
- `app/Services/TourOptimizationService.php` — `optimize(int $userId, array $coordinates, string $mode)`; pass `mode` to every `TourCache` call and to the dispatched job.
- `app/Services/TourCache.php` — add `string $mode` to `tourKey`, `activeJobKey`, `getTour`, `putTour`, `claimActiveJob`, `getActiveJob`, `releaseActiveJob`.
- `app/Jobs/OptimizeTourJob.php` — new readonly `string $mode` ctor arg; pass it to `OpenStreetTspClient::optimize($coordinates, $this->mode)`; include `mode` in log context; use it in the `releaseActiveJob`/cache calls (both `handle` and `failed`).
- `app/Services/OpenStreetTspClient.php` — `optimize(array $coordinates, ?string $mode = null)`; use `$mode ?? $this->mode` in the `mode` query (mirror `OpenStreetRouteClient`).

Front-end — **change**:
- `resources/js/types/tour.ts` — add `DeliveryMode` union + `DELIVERY_MODES` ordered list; add `mode: DeliveryMode` to the `done` (and pending) optimize state.
- `resources/js/components/tour/mode-select.tsx` — **new** dropdown (shadcn `Select`); props `{ value, onChange, disabled }`; trucking default; three labelled options.
- `resources/js/components/tour/tour-control-bar.tsx` — **new** (or inline in `optimize.tsx`) flex bar holding `ModeSelect` (left) + the Optimize button.
- `resources/js/components/tour/stop-list.tsx` — drop the Optimize button (moved to the control bar); keep the list.
- `resources/js/hooks/use-tour-optimization.ts` — `optimize(mode)`; thread mode through submit body, pending, and `done` state.
- `resources/js/hooks/use-tour-geometry.ts` — accept `mode` and send `{ stops, mode }`.
- `resources/js/pages/tour/optimize.tsx` — own `mode` state (default trucking), render the control bar, pass mode to `optimize` and the snapshotted `state.mode` to `useTourGeometry`.

Tests:
- `tests/Unit/DeliveryModeTest.php` — **new**: enum values + default.
- `tests/Unit/TourCacheTest.php` — extend: identical coords + different mode ⇒ different keys/entries; no cross-mode hit.
- `tests/Feature/TourOptimizationTest.php` — extend: mode validation (422 on bad mode), omitted mode → config default, mode reaches the TSP query and the job; a `walking` request does not return a cached `trucking` tour.
- `resources/js/hooks/use-tour-optimization.test.ts` — sends mode; `done` carries mode.
- `resources/js/hooks/use-tour-geometry.test.ts` — sends `mode` in the body.
- `resources/js/components/tour/mode-select.test.tsx` — **new**: defaults to trucking, lists three modes, fires onChange.

## Flow (mode-aware)

1. Planner picks a mode (default `trucking`) in the control-bar dropdown and clicks **Optimize route**.
2. Front POSTs `/api/tour/optimize` with `{ coordinates, mode }`.
3. `TourOptimizationService::optimize` hashes coordinates (order-independent) and looks up the cache
   **under that mode**; on miss, claims the per-mode active-job slot and dispatches `OptimizeTourJob`
   carrying `mode`.
4. The job calls `OpenStreetTspClient::optimize($coordinates, $mode)`; the TSP query carries the chosen
   `mode`; the tour is cached **under the mode**, and broadcast as today.
5. Front shows the result, snapshotting the mode into the `done` state.
6. `useTourGeometry(result, mode)` POSTs `/api/tour/geometry` with `{ stops, mode }`; the road path is
   traced for the **same** mode (FR-007). 002's per-leg/whole-tour fallback + logging unchanged.

## API Contract changes

- `POST /api/tour/optimize` request gains an optional `mode` (`trucking|driving|walking`, default
  `trucking` via config). Responses unchanged.
- `POST /api/tour/geometry` unchanged (already accepts `mode`); the front now always sends it.

## Design Artifacts (this run)

- `research.md` — mode-set verification status + cache-key & enum decisions.
- `data-model.md` — `DeliveryMode`, mode-aware cache keys, request/state shapes.
- `contracts/delivery-mode.md` — the two endpoints' mode field + the dropdown UI contract.
- `quickstart.md` — env, run, manual verification of all three modes.

---

Generated by speckit.plan on 2026-06-08
