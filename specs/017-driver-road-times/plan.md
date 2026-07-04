# Implementation Plan: Driver Road-Time Breakdown

**Branch**: `017-driver-road-times` | **Date**: 2026-07-04 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/017-driver-road-times/spec.md`, building on features
013 (`WorkdayEstimator`, `TravelTimeService`, chained warehouse↔tour connections), 014 (driver
list + `legs`), 016 (result-view driver list).

## Summary

Surface, on each available-driver row, the two travel legs that bracket the candidate tour —
**Road to tour** (`time_to_tour`) and **Road to warehouse** (`time_from_tour`) — and rename the
existing total figure "Projected" → **Total projected workday**. Both times are already computed
and summed into `projected_seconds`; the drivers endpoint gains two additive fields read from the
**already-preloaded** connection cache (no new routing call, `projected_seconds` unchanged).
Frontend adds two grey figures before the total. **Minimal blast radius** is the explicit goal:
only `DriverController`'s row closure changes on the backend; the estimator, start selection,
legs, availability query, and preload sets are untouched.

## Technical Context

**Stack**: Laravel 12 (PHP) + React 19 + Inertia + Tailwind v4 + shadcn/ui; PHPUnit
(`Http::fake`) + Vitest/Testing Library.

**Existing pieces reused (unchanged behavior)**:
- `TravelTimeService::durationBetween` (013) — cached read; the two bracketing connections are
  already preloaded by `connectionsAlongChain`, so reading them is a cache hit.
- `DriverController::incomingPoint` (013) — last prior tour's end, else warehouse.
- `WorkdayEstimator` / `projected_seconds` — **not touched** (no regression on the total).
- `formatDurationHm` + the row's muted label class — reused for the new figures.

**Project Type**: web app (Laravel + React SPA).

**Performance/Constraints**: zero added `/route` calls (both values from the preloaded cache);
`projected_seconds` byte-for-byte unchanged; additive payload (two ints).

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — new surfaces covered: `DriverAvailabilityTest` extended
  (`time_to_tour`/`time_from_tour` present + equal to the bracketing connection durations; null
  when that leg is unroutable; **`projected_seconds` and route-call count unchanged**);
  `use-tour-drivers` (maps both fields); `driver-list` (three figures in order, "Total projected
  workday" label, "Unavailable" for a null leg). PASS.
- **II/III. Readable & Simple** — two direct `durationBetween` reads in the existing closure
  (no new service, no widened estimator API); frontend adds a small three-figure block. Single
  responsibility preserved. PASS.
- **IV. Robustness** — null legs render "Unavailable" (never a misleading 0); the total keeps its
  existing `projected_incomplete` marking; failure logging for unroutable connections already
  emitted by `TravelTimeService` (unchanged). PASS.
- **V. Performance with Clarity** — no new routing; cache reads only; payload grows by two ints.
  PASS.
- **VI. Consistent, Reusable Styling** — new figures reuse the existing muted label class
  (`text-muted-foreground` uppercase); no raw color, no new palette entry. PASS.

No violations. (Complexity Tracking omitted.)

## Decisions

Full rationale + alternatives in [research.md](research.md); condensed:

- **D1 — Read the two durations in the controller row closure from the preloaded cache; do not
  widen `WorkdayEstimator`.** Keeps the total's code path (and value) untouched — smallest
  regression surface. (research D1)
- **D2 — `int|null`, null = unroutable** → "Unavailable" on the front; total's approximate flag
  unchanged. (research D2)
- **D3 — Field names `time_to_tour` / `time_from_tour`** (user-specified); `time_from_tour` =
  "Road to warehouse", documented in the contract. (research D3)
- **D4 — Additive frontend display**; no change to ordering/selection/preview/assign. (research D4)

## Project Structure (feature-specific)

Backend — **change (one file, one closure)**:
- `app/Http/Controllers/DriverController.php` — add `$travelTime` to the `$driverRows` closure
  `use(...)`; per row compute `time_to_tour = durationBetween(incoming, start.start, mode)` and
  `time_from_tour = durationBetween(start.end, warehouse, mode)` and add them to the returned
  array. Nothing else in the controller changes.

Frontend — **change**:
- `resources/js/types/tour.ts` — `Driver` gains `timeToTour: number | null`,
  `timeFromTour: number | null`.
- `resources/js/hooks/use-tour-drivers.ts` — map `time_to_tour`/`time_from_tour`.
- `resources/js/components/tour/driver-list.tsx` — right block becomes three figures
  (Road to tour, Road to warehouse, Total projected workday); relabel "Projected"; `null` →
  "Unavailable"; reuse the muted label class.

Tests: `tests/Feature/DriverAvailabilityTest.php` (extend — new fields, null case, unchanged
total + call count), `resources/js/hooks/use-tour-drivers.test.ts` (extend — mapping),
`resources/js/components/tour/driver-list.test.tsx` (extend — three figures/order/label/unavailable).

Out of scope: breaking out connections **between** a driver's earlier tours (stay folded in the
total); any change to the workday map preview, driver ordering, or assignment.

## Flow

1. `GET /api/tour/drivers` responds with the existing row **plus** `time_to_tour` /
   `time_from_tour` (from the already-preloaded cache; `projected_seconds` unchanged).
2. `useTourDrivers` maps them onto `Driver`.
3. `DriverList` renders three right-aligned figures per row (Road to tour · Road to warehouse ·
   Total projected workday); an unroutable leg shows "Unavailable".

## API contracts (this run)

- `GET /api/tour/drivers?mode&date&tour` — response gains `time_to_tour`, `time_from_tour`
  (`int|null`). Request, validation, all prior fields, and routing-call count unchanged. See
  `contracts/driver-road-times.md`.

## Design Artifacts (this run)

- `research.md` — reused slice + decisions D1–D4.
- `data-model.md` — additive API field + frontend view-model + display + invariants (no DB change).
- `contracts/driver-road-times.md` — the two added fields + label mapping.
- `quickstart.md` — verification incl. the `projected_seconds`/call-count regression guard.

---

Generated by speckit.plan on 2026-07-04
