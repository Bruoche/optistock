# Implementation Plan: Per-Stop Delivery Duration & Tour Duration Total

**Branch**: `007-stop-duration` | **Date**: 2026-06-10 | **Spec**: [spec.md](spec.md)

## Summary

Each stop in the tour editor gains an editable **delivery duration** (minutes, default 10). On optimize, the
frontend sends a parallel `durations` array; the backend sums it into a new **`wait_time_s`** response field
(seconds) — computed in the controller, **not** sent to the OpenStreet API and **not** part of the optimize
cache key. The result screen now shows two figures: the existing travel time relabeled **"Time on road"**,
and a new **"Tour duration"** = `(time on road ?? 0) + wait_time`. With no road metrics yet (2-point tour),
Tour duration is just the stop total; once the geometry trace responds, it becomes `road duration + stop
total`.

The change deliberately stays off the heavy async path: `wait_time_s` is returned in the immediate
optimize response (200/202) and carried through `OptimizeState` exactly like `mode`/`loop` — the queue job,
`TourOptimized` broadcast, status-poll endpoint, and `TourCache` are untouched.

## Technical Context

**Stack**: Laravel 12 (PHP) backend + React 19 + Inertia + Tailwind v4 + shadcn/ui frontend.

**Storage**: none added — durations are transient request/UI data; `wait_time` is derived.

**Testing**: PHPUnit (`Tests\TestCase`) for backend; Vitest + Testing Library for frontend.

**Request style**: existing `/api/tour/optimize` JSON `fetch` (see `use-tour-optimization.ts`). Only the
request body (`durations`) and response (`wait_time_s`) change.

**Current state (touch points)**:
- `app/Http/Requests/OptimizeTourRequest.php` — coordinate/mode/loop rules; gains optional `durations`.
- `app/Http/Controllers/TourOptimizationController.php` — computes `wait_time_s` from the request, adds it to
  the 200/202 response.
- `app/Services/OpenStreetTspClient.php` / `OptimizeTourJob.php` / `TourCache.php` — **unchanged** (durations
  never reach the upstream call or the cache key).
- `resources/js/types/tour.ts` — `Stop` gains `durationMinutes`; `OptimizeState` gains `waitTimeS`.
- `resources/js/hooks/use-tour-optimization.ts` — default duration on add, a `setStopDuration`, send
  `durations`, capture + carry `wait_time_s`.
- `resources/js/components/tour/stop-list.tsx` — per-row minutes input.
- `resources/js/components/tour/result-summary.tsx` — relabel to "Time on road" + add "Tour duration".
- `resources/js/pages/tour/optimize.tsx` — wire `setStopDuration`; pass `waitTimeS` to `ResultSummary`.

**Project Type**: web app (Laravel + React SPA).

**Performance/Scale**: an O(n≤10) integer sum per request; no extra upstream call. Duration edits never
invalidate the TSP cache.

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — backend Feature test (`wait_time_s` = sum×60 on cache miss *and* cache hit;
  durations NOT forwarded to the TSP client; `422` on size mismatch / negative / non-integer / >1440; omitted
  `durations` → default 10) + frontend tests (StopList default 10, independent edit, value retained across
  add/remove; ResultSummary shows both figures; Time-on-road "Unavailable" → Tour duration = wait only;
  recompute when road metrics arrive). PASS.
- **II/III. Readable & Simple** — a controller-level sum, one new optional request field, reuse of the
  existing `formatDuration` helper and the `mode`/`loop` state-carry pattern; no job/cache/broadcast changes.
  PASS.
- **IV. Robustness** — durations validated (integer, 0–1440, size-aligned) → `422`; missing → default 10;
  null travel time coerced to 0 so totals are never `NaN`/negative; invalid client input coerced to a valid
  value. No silent failure. PASS.
- **V. Performance with Clarity** — trivial sum; `wait_time` kept out of the TSP cache key so a duration edit
  on an identical route never re-fires the multi-minute upstream call. PASS.
- **VI. Consistent, Reusable Styling** — the minutes input uses the shared `Input`/`ui` primitives; the two
  result figures reuse the existing `bg-primary` stat block + `text-text-on-color` role vars; no raw hex,
  no duplicated style. PASS.

No violations.

## Decisions

- **D1 — `wait_time` computed in the controller, returned as a response sibling, never cached with the tour.**
  `wait_time_s = sum(durations_minutes) * 60`. The expensive TSP result stays keyed by
  `(mode, loop, coordinatesHash)`; `wait_time` is recomputed per request so a cache hit still returns a fresh
  value for the durations just sent. Durations are never sent to OpenStreet. (research R1.)

- **D2 — `wait_time_s` carried client-side through `OptimizeState`, not through the job/broadcast.** Returned
  in the immediate 200/202 response and snapshotted like `mode`/`loop` (state + ref), so an async result
  settled from a broadcast/poll still has it. Job, `TourOptimized`, status endpoint, `TourCache` untouched.
  (research R2.)

- **D3 — Durations travel as an optional parallel `durations` array** (`integer`, `min:0`, `max:1440`, size
  == coordinates when present; default 10 each when omitted). Leaves the strict `[lat,lng]` coordinate rules
  intact; order-alignment is cosmetic since `wait_time` is an order-independent sum. (research R3.)

- **D4 — Seconds end to end.** `wait_time_s` in seconds adds directly to `total_duration_s` /
  road `duration_s`; both displayed figures reuse `formatDuration(seconds)`. Null travel time → 0 toward the
  Tour duration (FR-011). (research R4.)

- **D5 — Durations edited pre-optimize; Tour duration recalculates live only on road-metric arrival.** The
  done view shows the two totals; changing durations means a new tour (reset), consistent with `mode`/`loop`
  being fixed once `done`. (research R5.)

- **D6 — Relabel, don't add a third figure.** The value currently labeled "Tour duration" in
  `result-summary.tsx` becomes **"Time on road"**; the new **"Tour duration"** is the sum. Two figures total.

## Project Structure (feature-specific)

Backend — **change only**:
- `app/Http/Requests/OptimizeTourRequest.php` — add `durations` rules (+ messages).
- `app/Http/Controllers/TourOptimizationController.php` — sum request durations → `wait_time_s`; add to the
  200 and 202 responses. (Default to 10/stop when `durations` absent.)

Frontend — **change only**:
- `resources/js/types/tour.ts` — `Stop.durationMinutes`; `OptimizeState.waitTimeS`.
- `resources/js/hooks/use-tour-optimization.ts` — default 10 on `addStop`; `setStopDuration(id, minutes)`;
  send `durations`; read `payload.wait_time_s` into state + a ref; carry to `done`.
- `resources/js/components/tour/stop-list.tsx` — per-row minutes input + `onDurationChange`.
- `resources/js/components/tour/result-summary.tsx` — "Time on road" + "Tour duration"; take `waitTimeS`.
- `resources/js/pages/tour/optimize.tsx` — pass `onDurationChange` to `StopList`, `waitTimeS` to `ResultSummary`.

Tests:
- `tests/Feature/TourOptimizationTest.php` (or the existing optimize feature test) — `wait_time_s` value on
  cache miss + hit; durations not forwarded to the TSP client (fake/mock asserts coordinates-only); validation
  422s; default-10 when omitted.
- `resources/js/components/tour/stop-list.test.tsx` — default 10, independent edit, lock state.
- `resources/js/components/tour/result-summary.test.tsx` — both figures; Unavailable → wait-only; recompute on
  road metrics.

Out of scope:
- Persisting durations or tours; editing durations from the done view; sending durations upstream; any change
  to the geometry/status/broadcast paths.

## Flow

1. Planner adds stops → each defaults to `durationMinutes: 10`; edits some in `StopList`.
2. Optimize → `use-tour-optimization` POSTs `{ coordinates, mode, loop, durations }`.
3. Controller validates, computes `wait_time_s = sum(durations)*60`, dispatches/serves the tour as today,
   and returns `wait_time_s` in the 200/202 body. Durations do **not** reach the TSP client or cache key.
4. Frontend stores `wait_time_s` in `OptimizeState` (carried via ref for async settle).
5. On `done`, `ResultSummary` shows **Time on road** (`roadMetrics?.duration_s ?? total_duration_s`, null →
   "Unavailable") and **Tour duration** (`(deliveryS ?? 0) + waitTimeS`).
6. When the geometry trace responds, `roadMetrics` overrides the estimate → both figures update.

## API contract

See [contracts/optimize-wait-time.md](contracts/optimize-wait-time.md). `POST /api/tour/optimize` gains an
optional `durations` array and returns `wait_time_s` (seconds) in the 200/202 body; `422` on invalid
durations. Status endpoint, broadcast, and geometry endpoint unchanged.

## Design Artifacts (this run)

- `research.md` — wait_time placement vs. the TSP cache trap, sync/async delivery, request shape, units, UX.
- `data-model.md` — transient `Stop.durationMinutes`, the `durations` field, derived `wait_time_s` /
  `tourDurationS`; constraints CR-1..CR-4.
- `contracts/optimize-wait-time.md` — request/response deltas + the `ResultSummary` / `StopList` UI contracts.
- `quickstart.md` — manual verification (defaults, two totals, 2-point 0-delivery example, cache, validation).

---

Generated by speckit.plan on 2026-06-10
