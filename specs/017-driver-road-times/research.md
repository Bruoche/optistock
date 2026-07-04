# Research — Driver Road-Time Breakdown

Small feature: expose two already-computed connection durations per driver row and
display them; rename one label. Minimal blast radius is the explicit goal (user).

## Existing slice

- **`DriverController::available`** builds each driver row. It already holds, per driver:
  - `$driver->warehouse->coordinate` (warehouse),
  - `$workday['start']` — the candidate tour's chosen start/end (`TourStartSelector`),
  - `$workday['prior_tours']` — the driver's earlier tours that day,
  - and calls `incomingPoint($driver, $priorTours)` = last prior tour's end, else warehouse.
- **`TravelTimeService::durationBetween($from,$to,$mode)`** returns cached road seconds
  (0 coincident, null unroutable). The controller already **preloads** every chain
  connection via `connectionsAlongChain` — which includes exactly `[incoming → candidate
  start]` and `[candidate end → warehouse]`. So reading those two back is a **cache hit**.
- **`WorkdayEstimator::total`** sums the whole chain into `projected_seconds`
  (`projected_incomplete` when any leg is null). The two bracketing connections are already
  inside that sum.
- **Frontend**: `useTourDrivers` maps the payload → `Driver`; `DriverList` renders each row
  with a single right-aligned "Projected" figure (`formatDurationHm`).

## Decisions

- **D1 — Compute the two fields in the controller from cached connections; do NOT widen
  `WorkdayEstimator`.** In the existing row closure add
  `time_to_tour = durationBetween(incoming, start.start, mode)` and
  `time_from_tour = durationBetween(start.end, warehouse, mode)`. Both hit the preloaded
  cache → **no new HTTP call, `projected_seconds` byte-for-byte unchanged**. Only the row
  closure and its `use(...)` change; start selection, legs, ordering, availability query,
  preload sets, and the estimator are untouched.
  - *Rejected*: making `WorkdayEstimator` return per-connection durations — touches the
    total's code path (regression surface) for data the controller can already read.
  - *Rejected*: a second routing pass — the connections are already preloaded; re-fetching
    would add calls the user explicitly wants avoided.

- **D2 — Nullable seconds, null = unroutable.** `time_to_tour` / `time_from_tour` are
  `int|null`, mirroring `durationBetween`. Null renders as "Unavailable" on the front
  (FR-007); the total keeps its existing `projected_incomplete` flag untouched.

- **D3 — Field names per the user: `time_to_tour`, `time_from_tour`.** `time_from_tour` =
  "Road to warehouse" (from the tour back to the warehouse). Documented in the contract so
  the label mapping is unambiguous.

- **D4 — Frontend: additive display only.** `Driver` gains `timeToTour`/`timeFromTour`;
  `useTourDrivers` maps them; `DriverList`'s right block becomes three figures (Road to tour,
  Road to warehouse, Total projected workday) reusing the existing muted label style;
  "Projected" → "Total projected workday". No change to rows' selection, preview, or assign.

## No open questions

Spec has no NEEDS CLARIFICATION. Field names fixed by the user.
