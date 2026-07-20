# Phase 1 Data Model: Manual Tour Duration Fallback

No new tables, columns, or migrations. This documents the existing entities the force path writes/reads and the exact values a forced tour carries.

## Tour (`tours`) — reused

| Field | Optimized tour | **Forced tour** |
|-------|----------------|-----------------|
| `user_id` | owner | owner (same) |
| `delivery_mode_id` | selected/default mode | selected/default mode (same) |
| `loop` | selected shape | selected shape (same) |
| `travel_duration_s` | driving total from `/tsp` (`STEPS_DURATIONS.TOTAL`) | **manual drive seconds** (typed minutes × 60) |
| `total_distance_m` | distance from `/tsp`, or null | **null** (no upstream call; may be backfilled later by geometry as today) |

Accessor `total_duration_s = travel_duration_s + Σ stop.duration_s` — non-null for a forced tour (drive is set), so it contributes a concrete figure to the workday. Unchanged logic.

**State**: a forced tour is `unassigned` on creation → assignable via `driver_tour` exactly like an optimized tour. Editing an existing unassigned tour with `tour_id` overwrites it in place (same rule as optimize edit, feature 020). An **assigned** tour cannot be forced (422), same as it cannot be re-optimized.

## Stop (`stops`) — reused

| Field | Forced tour |
|-------|-------------|
| `latitude`, `longitude` | request coordinate, normalized (`CoordinateNormalizer`) — same precision as an optimized stop |
| `duration_s` | request per-stop delivery duration, **saved unchanged** |
| `position` | **input order index** (0,1,2,…) — no reorder |

Invariant preserved: a tour and its stops are written in one transaction; a mid-write / vanished-edit-target failure rolls back and surfaces `persist_failed` (never a silent partial or duplicate).

## Validation (request → force)

| Field | Rule | Source |
|-------|------|--------|
| `stops` | `required, array, min:2, max:10` | inherited from `OptimizeTourRequest` |
| `stops.*.lat` / `.lng` | `required, numeric, between ±90 / ±180` | inherited |
| `stops.*.duration_s` | `required, integer, min:0` | inherited |
| `mode` | `sometimes, enum(DeliveryMode)` (default trucking) | inherited |
| `loop` | `sometimes, boolean` (default true) | inherited |
| `tour_id` | `sometimes, integer, owned + unassigned` (foreign/missing → 404, assigned → 422) | inherited (authorize + rule) |
| **`travel_duration_s`** | **`required, integer, min:1, max:86400`** | **new** |

## Frontend view-model deltas

- `OptimizeState` `done` variant gains `forced?: boolean` (true only via `forceTour`).
- No change to `TourResult` (a forced tour uses the same shape; `total_distance_m` null, `total_duration_s` = manual drive seconds).
- New client constants: `MAX_TOUR_DURATION_MINUTES = 1440`; a force error maps to the existing `TourError` union (`persist_failed` / `invalid_response` / `api_error`).

## Driver-assignment path (read-only here — audit target)

No data-model change. A forced tour enters `DriverAvailabilityService::rowsFor` as the candidate `Tour`; `candidateTour->total_duration_s` (now concrete) feeds `TourSegment`. Prior tours, warehouse chaining, mandatory breaks, and the `driver_tour` write are unchanged. The audit concerns *robustness of the external `/route` calls* (bounded, non-throwing, flagged), not the data.
