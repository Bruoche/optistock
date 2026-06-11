# Implementation Plan: Per-Stop Delivery Duration & Tour Duration Total

**Branch**: `007-stop-duration` | **Date**: 2026-06-11 | **Spec**: [spec.md](spec.md)

## Summary

Each stop in the tour editor gains an editable **delivery duration** (minutes, default 10). After optimize,
the result screen shows two figures: the existing travel time relabeled **"Time on road"**, and a new
**"Tour duration"** = `(time on road ?? 0) + Σ stop durations`.

**This feature is frontend-only.** Stop durations are pure client state and the stop total is a trivial
order-independent sum the frontend already has every input for. Sending durations to the backend only to have
it echo the sum back adds a request field, a response field, validation, cache caveats, and state-machine
plumbing — all for a number the client can compute itself. So nothing leaves the browser: the optimize
request, its response, the queue job, the `TourOptimized` broadcast, the status-poll endpoint, and
`TourCache` are **all untouched**. `wait_time` (seconds) is derived on the front from the stops' durations and
passed straight to the result view.

With no road metrics yet (2-point tour), Tour duration is just the stop total; once the geometry trace
responds, it becomes `road duration + stop total`.

## Technical Context

**Stack**: Laravel 12 (PHP) backend + React 19 + Inertia + Tailwind v4 + shadcn/ui frontend.

**Storage**: none — durations are transient client UI state; the stop total is derived at render.

**Testing**: Vitest + Testing Library (frontend only — no backend behavior changes).

**Request style**: the existing `/api/tour/optimize` JSON `fetch` (see `use-tour-optimization.ts`) is
**unchanged** — no new request field, no new response field.

**Current state (touch points — all frontend)**:
- `resources/js/types/tour.ts` — `Stop` gains `durationMinutes`; add a `DEFAULT_STOP_DURATION_MINUTES`
  (10) and `MAX_STOP_DURATION_MINUTES` (1440) constant. `OptimizeState` is **unchanged**.
- `resources/js/hooks/use-tour-optimization.ts` — `addStop` assigns the default duration; add
  `setStopDuration(id, minutes)` (coerces invalid input); expose a derived `waitTimeS` = `Σ durationMinutes ×
  60`. The optimize POST body is **unchanged** (coordinates/mode/loop only).
- `resources/js/components/tour/stop-list.tsx` — per-row minutes input + `onDurationChange`.
- `resources/js/components/tour/result-summary.tsx` — relabel existing figure to "Time on road"; add
  "Tour duration"; accept a `waitTimeS` prop. (`formatDuration` already lives here, local — reused for both.)
- `resources/js/pages/tour/optimize.tsx` — wire `setStopDuration` into `StopList`; pass the hook's `waitTimeS`
  to `ResultSummary`.

**Untouched (explicitly)**: `app/Http/Controllers/TourOptimizationController.php`,
`app/Http/Requests/OptimizeTourRequest.php`, `OpenStreetTspClient.php`, `OptimizeTourJob.php`,
`TourCache.php`, the broadcast, the status endpoint, and all of `config/`.

**Project Type**: web app (Laravel + React SPA).

**Performance/Scale**: an O(n≤10) integer sum in the browser, recomputed on render. Zero backend cost; the TSP
cache is structurally untouchable by duration edits (durations never reach it).

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — frontend tests only: StopList (default 10, independent edit, value retained
  across add/remove, input locked while optimizing); the hook (`addStop` default; `setStopDuration` coerces
  empty/NaN/negative→0, floors non-integers, clamps >1440; `waitTimeS` = Σ×60; durations preserved on
  `removeStop`); ResultSummary (both figures; Time-on-road "Unavailable" → Tour duration = wait only;
  recompute when road metrics arrive). PASS.
- **II/III. Readable & Simple** — the simplest correct design: no backend change, no API change, no
  state-machine field. One client-side sum, one new client constant, reuse of the local `formatDuration` and
  the existing row/stat-block markup. The earlier round-trip is removed as unnecessary complexity. PASS.
- **IV. Robustness** — durations coerced to a valid non-negative whole number in `setStopDuration` (empty/
  NaN/negative→0, non-integers floored, clamped to 1440), so the total is never `NaN`/negative; null travel
  time coerced to 0 toward Tour duration (FR-011). No silent failure — invalid input is visibly corrected to a
  valid value in the field. PASS.
- **V. Performance with Clarity** — a trivial in-browser sum; the multi-minute upstream TSP call and its cache
  are entirely off this path (durations never touch them), so a duration edit can never re-fire it. PASS.
- **VI. Consistent, Reusable Styling** — the minutes input uses the shared `Input`/`ui` primitives; the two
  result figures reuse the existing `bg-primary` stat block + `text-text-on-color` role vars; no raw hex, no
  duplicated style. PASS.

No violations.

## Decisions

- **D1 — The stop total is computed on the frontend; the backend never sees durations.**
  `waitTimeS = Σ(durationMinutes) × 60`, derived from the hook's `stops`. Durations are client-only UI state;
  the sum is order-independent and needs no input the client lacks. Round-tripping it through the optimize
  request/response (the prior design) added a request field, a response field, server validation, a cache
  caveat, and `OptimizeState` plumbing for zero added capability. Dropping all of that is the simpler, lower-
  risk design. The expensive TSP result stays keyed by `(mode, loop, coordinatesHash)`, structurally
  unreachable by a duration edit. (research R1.)

- **D2 — No `OptimizeState` carry; `waitTimeS` is derived live from `stops`.** Stops are frozen between submit
  and `done` (the list is locked while optimizing, then `ResultSummary` replaces `StopList`), so
  `Σ durationMinutes` at render equals the durations the tour was optimized with — no snapshot/ref needed,
  unlike `mode`/`loop` which are call-time arguments. The hook exposes `waitTimeS` as a derived value; the page
  passes it to `ResultSummary`. (research R2.)

- **D3 — Durations live on the `Stop` view model with a frontend default.** `Stop.durationMinutes`, defaulted
  to `DEFAULT_STOP_DURATION_MINUTES = 10` in `addStop`. The default is a frontend constant (no server config),
  since the backend has no use for it. (research R3.)

- **D4 — Seconds end to end.** `waitTimeS` is in seconds so it adds directly to the existing second-based
  `total_duration_s` / road `duration_s`; both displayed figures reuse the local `formatDuration(seconds)`.
  Null travel time → 0 toward Tour duration (FR-011). (research R4.)

- **D5 — Durations edited pre-optimize; Tour duration recalculates live only on road-metric arrival.** The
  done view shows the two totals; changing durations means a new tour (reset), consistent with `mode`/`loop`
  being fixed once `done`. (research R5.)

- **D6 — Relabel, don't add a third figure.** The value currently labeled "Tour duration" in
  `result-summary.tsx` becomes **"Time on road"**; the new **"Tour duration"** is the sum. Two figures total.

## Project Structure (feature-specific)

Backend — **no changes**. (Previous draft touched the controller and request; both are now left as-is.)

Frontend — **change only**:
- `resources/js/types/tour.ts` — `Stop.durationMinutes`; `DEFAULT_STOP_DURATION_MINUTES` /
  `MAX_STOP_DURATION_MINUTES` constants. `OptimizeState` unchanged.
- `resources/js/hooks/use-tour-optimization.ts` — default duration on `addStop`; `setStopDuration(id, minutes)`
  with coercion; expose derived `waitTimeS`. POST body unchanged.
- `resources/js/components/tour/stop-list.tsx` — per-row minutes input + `onDurationChange`.
- `resources/js/components/tour/result-summary.tsx` — "Time on road" + "Tour duration"; take `waitTimeS`.
- `resources/js/pages/tour/optimize.tsx` — pass `onDurationChange` to `StopList`, `waitTimeS` to `ResultSummary`.

Tests (frontend only):
- `resources/js/hooks/use-tour-optimization.test.ts` — `addStop` default 10; `setStopDuration` coercion +
  independence; `removeStop` preserves durations; `waitTimeS` = Σ×60.
- `resources/js/components/tour/stop-list.test.tsx` — default 10, independent edit, locked state.
- `resources/js/components/tour/result-summary.test.tsx` — both figures; Unavailable → wait-only; recompute on
  road metrics.

Out of scope:
- Persisting durations or tours; editing durations from the done view; any backend, request, response, job,
  broadcast, cache, or `config/` change.

## Flow

1. Planner adds stops → each defaults to `durationMinutes: 10`; edits some in `StopList`.
2. Optimize → `use-tour-optimization` POSTs `{ coordinates, mode, loop }` (**no durations**) and serves the
   tour exactly as today.
3. The hook derives `waitTimeS = Σ(durationMinutes) × 60` from its `stops`.
4. On `done`, `ResultSummary` shows **Time on road** (`roadMetrics?.duration_s ?? total_duration_s`, null →
   "Unavailable") and **Tour duration** (`(deliveryS ?? 0) + waitTimeS`).
5. When the geometry trace responds, `roadMetrics` overrides the estimate → both figures update.

## API contract

`POST /api/tour/optimize` is **unchanged** — no `durations` request field, no `wait_time_s` response field.
See [contracts/optimize-wait-time.md](contracts/optimize-wait-time.md) for the (unchanged) API note plus the
frontend `StopList` / `ResultSummary` UI contracts that this feature does add.

## Design Artifacts (this run)

- `research.md` — why the sum moved fully to the frontend, live-derive vs. state carry, the client default,
  units, UX.
- `data-model.md` — transient `Stop.durationMinutes`, the derived `waitTimeS` / `tourDurationS`; constraints
  CR-1..CR-3.
- `contracts/optimize-wait-time.md` — API-unchanged note + the `ResultSummary` / `StopList` UI contracts.
- `quickstart.md` — manual verification (defaults, two totals, 2-point 0-delivery example, client coercion).

---

Generated by speckit.plan on 2026-06-11 (reworked frontend-only)
