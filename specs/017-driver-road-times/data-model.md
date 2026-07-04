# Data Model — Driver Road-Time Breakdown

No database changes. One additive API-response change + matching frontend view-model + display.

## API response (`GET /api/tour/drivers`) — additive only

Each driver row in `data[]` gains two fields; **all existing fields unchanged**:

| Field | Type | Meaning |
|-------|------|---------|
| `time_to_tour` | `int \| null` | Road seconds from the driver's incoming point (last prior tour's end, else warehouse) to the candidate tour's chosen start. `null` = that connection is unroutable. |
| `time_from_tour` | `int \| null` | Road seconds from the candidate tour's chosen end back to the warehouse. `null` = unroutable. |

Both equal the corresponding connection already summed into `projected_seconds`
(`durationBetween`, read from the preloaded cache — no new routing call). `0` for a
coincident point.

## Backend (`DriverController::available` — row closure only)

- Capture `$travelTime` in the `$driverRows` closure `use(...)`.
- Per row: `$incoming = incomingPoint($driver, $workday['prior_tours'])`;
  `time_to_tour = $travelTime->durationBetween($incoming, $workday['start']->start, $mode->value)`;
  `time_from_tour = $travelTime->durationBetween($workday['start']->end, $warehouse, $mode->value)`.
- No other method, query, preload, or service touched. `projected_seconds` /
  `projected_incomplete` / `start_index` / `legs` / ordering identical.

## Frontend view model (`Driver`, `resources/js/types/tour.ts`)

| Field | Type | Source |
|-------|------|--------|
| `timeToTour` | `number \| null` | `time_to_tour` |
| `timeFromTour` | `number \| null` | `time_from_tour` |

`useTourDrivers` maps the two new payload fields; every other mapped field unchanged.

## Display (`DriverList` row)

Right-aligned block becomes three figures, left→right:

1. **Road to tour** — `timeToTour`
2. **Road to warehouse** — `timeFromTour`
3. **Total projected workday** — `projectedSeconds` (relabelled from "Projected")

- Duration formatting: `formatDurationHm`; `null` → "Unavailable".
- The two new labels reuse the existing muted label style (same `text-muted-foreground`
  uppercase class as today's "Projected" label).
- The `projectedIncomplete` warning icon stays on the total figure only.

## Invariants

- `time_to_tour` / `time_from_tour` are exactly the bracketing connections of
  `projected_seconds` — never independently recomputed (FR-008).
- Adding the fields changes neither the number of routing calls nor `projected_seconds`.
- Display is additive: driver ordering, selection, workday preview, and assignment unaffected.
