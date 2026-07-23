# Contract: Reorder the day's tours

## `POST /api/driver/{driver}/tour-order` (api, JSON)

Auth: `auth`. Throttle: `tour-read`. Unknown driver → 404.

### Request
```
{ "date": "YYYY-MM-DD",
  "tour_ids": [12, 7, 30],     // the full day, in the desired running order
  "force": false }             // optional; true = degrade past a routing failure
```

### Validation / preconditions
- `date` required `Y-m-d`; `tour_ids` required, non-empty int array.
- **Conflict (FR-034)**: `set(tour_ids)` MUST equal the set of tour ids currently assigned to this driver on `date`. Otherwise **409** `{ code: "assignment_conflict", message }` — the client refetches the day and discards the pending order. Guards against a concurrent assign/delete.

Uses the day's single mode (FR-045), derived from the day's tours, for all routing.

### Recompute + persist (normal, `force:false`)
- `preload` the chain's connections once, then chain the new order from the warehouse, selecting each tour's entry via `TourStartSelector` and deducing its exit (see data-model.md recompute rule).
- **Detect failure explicitly**: `TourStartSelector::select` returns the first candidate on all-null durations, so it does NOT signal routing health. The service measures each chain connection (warehouse→first, between, last→warehouse) directly and checks for null itself.
- If every measured connection is non-null → persist new `sequence` (0-based, in submitted order) and recomputed `start_/end_lat/lng` per `driver_tour` row, in one transaction → **200** `{ data: DriverDayData }` (the fresh day, so map + figures update from the response).
- If any measured connection is null → **422** `{ code: "unroutable_connection", message, failed_leg: { from:[lat,lng], to:[lat,lng] } }`. **Nothing persisted.** The client reveals a **Force save** control.

### Force persist (`force:true`)
- **Routing-free** — MUST NOT call `TourStartSelector::select` (that re-issues the doomed API calls). Each tour's entry is its lowest-position start candidate, its exit deduced by `endStopForStart`; persist `sequence` + those points in one transaction → **200** `{ data: DriverDayData }`.
- The degraded path is logged at `warning` (operation, driver, date) per Constitution IV. Any still-unmeasurable figure in the returned day shows as unavailable (`incomplete:true`).

### Invariants
- Only `sequence` and (normal save) `start/end` change. `tour_id`, `driver_id`, `date`, stop set, per-stop durations, tour contents, and driver ownership are never modified (FR-035).
- One driver per tour (pivot uniqueness) preserved.

### Client behaviour
- Tour-order Update is disabled until a drag changes the order; a single-tour day keeps it disabled.
- On 200 the pending-order state clears and the map re-labels T-markers from the returned order.
- Changing day or pressing a row's Edit while an unsaved order exists warns first (FR-041).
