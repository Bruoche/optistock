# Research: Inter-Tour Travel Time

**Feature**: 013-inter-tour-travel | **Date**: 2026-07-02

Builds directly on 012 (persisted `tours`/`stops`/`driver_tour`, the `GET /api/tour/drivers`
projected-hours figure, and the `POST /api/tour/{tour}/assign` flow) and 002 (the
`OpenStreetRouteClient` / `TourGeometryService` road-tracing stack). This feature turns
the 012 "sum of tour durations" figure into a **full chained workday** and adds the
start/end-stop anchoring the chain needs.

## Existing data flow (relevant slice)

- **`GET /api/tour/drivers?mode&date`** → `DriverController::available` → `Driver::available`
  scope + `Driver::committedSecondsForDate` (one grouped aggregate summing
  `travel_duration_s + Σ stop.duration_s` per driver for the date). The frontend then
  *adds* the on-screen current-tour total to get the projected figure (012 D8).
- **`POST /api/tour/{tour}/assign { driver_id, date }`** → `TourAssignmentController` →
  `driver_tour` upsert via `sync` (unique `tour_id`).
- **`OpenStreetRouteClient::traceLeg(origin, destination, mode)`** already fetches **one
  leg's** road `distance_m` + `duration_s`, throwing `TourGeometryException` on failure.
  `TourGeometryService::trace` composes it over a whole tour and stays pure (002).
- `tours.travel_duration_s` is **nullable** (null = unknown road time, FR-012 of 012);
  the `Tour::total_duration_s` accessor **propagates null** (never `?? 0`).

## Decisions

### R1 — Warehouse table + mandatory driver→warehouse link

- New **`warehouses`** table: `id`, `name`, `latitude` decimal(10,7), `longitude`
  decimal(10,7), timestamps. Coordinates match the `stops` precision so a warehouse and
  a stop are directly comparable as `Coordinate`s.
- **`drivers.warehouse_id`** foreign key, **mandatory** (spec FR-001: a driver can never
  have zero warehouses), `restrictOnDelete` (a warehouse with drivers cannot be deleted).
  Relationship: `Driver belongsTo Warehouse`, `Warehouse hasMany Driver` (many-to-one).
- **Migration safety on the already-shipped `drivers` table** (012 is merged): the
  migration (a) creates `warehouses`, (b) inserts a **"Default warehouse"** row, (c) adds
  `warehouse_id` **defaulting to that row's id** so existing driver rows become valid and
  the column is NOT NULL, (d) adds the FK. The DB default is a **migration convenience for
  legacy rows only** — every application driver-creation path (factory, demo seeder, any
  future driver form) sets `warehouse_id` explicitly, so a real missing assignment is never
  silently masked (constitution IV).
- **Alternatives**: single global warehouse (rejected — clarification chose multiple, each
  driver one); nullable link with graceful degrade (rejected — clarification made it
  mandatory). See spec Clarifications 2026-07-02.

### R2 — `driver_tour` gains start/end coordinates + a sequence

- Add `start_latitude`, `start_longitude`, `end_latitude`, `end_longitude` decimal(10,7),
  and **`sequence`** unsignedInteger (the driver's ordering of the day's assigned tours —
  the max sequence for a driver+date is their current latest tour; a future re-ordering
  feature rewrites `sequence`). The existing `(driver_id, date)` index already serves the
  per-day ordered lookup.
- **Nullability**: the four coordinate columns are added **nullable** *purely* so the
  migration is safe on a `driver_tour` table that may already hold dev rows (no source
  exists to backfill a historical start/end). Going forward the **assign path always sets
  them**; the workday estimator treats a theoretical null (a pre-013 legacy row) defensively
  as an unknown leg rather than crashing. `sequence` is added with a default of `0` and
  existing rows are backfilled per driver+date by `id` order.
- **Coordinates, not a stop FK**: the user asked the association to "hold the start and end
  coordinate." Storing coordinates (not a `stop_id`) keeps the assignment self-contained for
  chain math (no join to `stops`) and independent of later stop edits. (research R5 chain
  reads these directly.)

### R3 — Tour exposes its valid start points and deduces the end

- **`Tour::startCandidates(): Collection<Stop>`**:
  - **looping** tour → **all** stops (a loop returns to its origin, so any stop is a valid
    entry/exit).
  - **one-way** tour → the **two endpoints** only: the stops at the min and max `position`.
    Interior stops are never candidates (spec FR-006).
- **`Tour::endStopForStart(Stop $start): Stop`** (or by position):
  - **looping** → the **same** stop (start = end, FR-005).
  - **one-way** → the **opposite** endpoint (choosing one end fixes the other, FR-006).
- Lives on the `Tour` model — it owns its geometry/shape (`loop`, ordered `stops`). This is
  the "Route returns all valid points it has" the user described, reused by both the drivers
  endpoint (candidate selection) and the assign endpoint (end deduction).

### R4 — A single-leg travel-duration provider, deduplicated + concurrently batched (FR-014)

- New **`TravelTimeService`** wrapping `OpenStreetRouteClient::traceLeg`, exposing
  `durationBetween(Coordinate $from, Coordinate $to, ?string $mode): ?int` — returns the
  leg's road seconds, or **null on failure** (caught, logged `warning` per constitution IV).
  Coincident points short-circuit to a genuine **0** (no call), distinct from null (FR-010).
- **Two-phase, deduplicated, capped batch** (the clarified concurrency requirement — spec
  Clarifications 2026-07-02 / FR-014). Rather than per-driver lazy sequential calls, the
  drivers endpoint:
  1. **Collects the distinct leg set** across all drivers — each leg keyed by
     `(rounded-from, rounded-to, mode)`, so identical warehouse/return/between legs shared
     between drivers appear once.
  2. **Fetches the outstanding legs with bounded concurrency** — a capped batch (Laravel
     `Http::pool` over chunks sized to a configured cap) so the routing API is sped up but
     not flooded / rate-limited (`LIMIT_REACHED`).
  3. **Populates a per-request duration map** (`legKey → ?int`); a raw pool response is
     mapped to a duration via the **same `OpenStreetRouteClient` parsing** (no duplicated
     response handling — the client exposes the mapping so the pool path reuses it), failure →
     null (logged).
  4. `WorkdayEstimator` then reads durations from this pre-filled map (a `durationBetween`
     lookup is a map hit; a miss for a coincident pair returns 0).
- **Reuse, not duplication**: `traceLeg` still serves single-leg needs and the pool path reuses
  the client's response→leg mapping. `OpenStreetRouteClient`'s parsing is factored so both the
  single-call and pooled paths share it. `TourGeometryService::trace` stays **untouched** (pure,
  002 whole-tour geometry). This is the "Trace route function called on each valid start / between
  each tour," now dedup+capped-concurrent per the clarification.

### R5 — `WorkdayEstimator`: the pure chain calculation

- New **`WorkdayEstimator`** domain service (injected `TravelTimeService`). Given a driver's
  **warehouse** coordinate, their **prior assigned tours for the date** (ordered by
  `sequence`, each carrying its saved start/end coordinates + its internal total seconds),
  and the **candidate** tour (its start candidates, `loop`, end-deduction, internal total
  seconds), it returns:
  `{ projected_duration_s: int, incomplete: bool, start_index: int, start: Coordinate, end: Coordinate }`
  (`incomplete` = a leg failed → best-effort lower bound, FR-009/FR-015).
- **Algorithm**:
  1. Chain = prior tours (fixed start/end from `driver_tour`) then the **candidate appended
     last** (assignment order — spec FR-013 / clarification).
  2. **Candidate start selection**: incoming point = the last prior tour's **end** coordinate,
     or the **warehouse** when the driver has no prior tour that day. For each candidate start,
     `durationBetween(incoming → start)`; pick the **minimum known** duration (deterministic
     tie-break: lowest index; all-unknown → lowest index). `end = endStopForStart(start)`.
  3. **Sum** = `durationBetween(W → chain.first.start)` + Σ between-legs
     `durationBetween(prev.end → next.start)` + `durationBetween(chain.last.end → W)` +
     Σ each segment's internal total. A **failed leg contributes 0** and sets an
     **`incomplete`** flag on the estimate (best-effort lower bound — FR-009/FR-015), rather
     than nulling the whole figure. The returned estimate is
     `{ projected_duration_s: int, incomplete: bool, start_index, start, end }`.
- **Between-tour legs are recomputed every call**, never stored (user requirement) — this
  keeps the figure correct once a future feature re-orders a driver's tours, since only the
  per-tour start/end coordinates + `sequence` are persisted.
- Pure and injectable → unit-testable with a fake `TravelTimeService`, and directly reusable
  by the later re-ordering feature.

### R6 — `GET /api/tour/drivers` now takes the tour + returns the chained figure

- Request gains a required **`tour`** id (the persisted candidate). `AvailableDriversRequest`
  validates it exists **and is owned** by the user (404 on a foreign id, mirroring the assign
  guard); the controller loads the tour + stops for `startCandidates`/`loop`/total.
- Per available driver: load the **warehouse** coordinate + **prior tours for the date**
  (`driver_tour` rows ordered by `sequence`, joined to their tour totals), run
  `WorkdayEstimator`, and emit per driver:
  - `warehouse_name` (shown on the driver row — "where they come from"),
  - `projected_seconds` (the **best-effort full chain**, `int`; failed legs count 0),
  - `projected_incomplete` (`bool` — a leg failed → figure flagged approximate, FR-015),
  - `start_index` (the selected candidate start's `position`).
- The old `assigned_seconds` field and the client-side "add current-tour total" step are
  **removed**: the server now returns the complete projected figure, so the frontend just
  formats it. This is the "function giving the projected workday duration will now use this
  calculation, and the api returns the selected starting point."
- Trade-off (R9): this is now a routing-heavy read.

### R7 — `POST /api/tour/{tour}/assign` takes the chosen start index

- Body gains **`start_index`** (the `position` the drivers payload selected for this driver —
  so assignment does **not** recompute the nearest start; the user's "api returns the selected
  starting point so the assignation function doesn't recalculate it").
- Still **validated, never trusted blindly**: `AssignTourRequest` checks `start_index` is a
  legal start `position` for the bound tour (a `startCandidates` position). The controller
  resolves the start `Stop`, deduces the **end** via `Tour::endStopForStart` (loop = same
  stop, one-way = opposite endpoint — "deduce the end from the start"), computes
  `sequence = max(sequence for driver+date) + 1`, and writes the `driver_tour` row with the
  start/end coordinates + sequence (still an idempotent `sync` on the unique `tour_id`).

### R8 — Frontend wiring

- `types/tour.ts` `Driver`: drop `assignedSeconds`; add `warehouseName: string`,
  `projectedSeconds: number`, `projectedIncomplete: boolean`, `startIndex: number`. Remove the
  `projectedSeconds()` add-helper (server now authoritative).
- `use-tour-drivers`: send `&tour=<id>`; map the four new fields.
- `driver-list.tsx`: show the warehouse name in the driver info block; render
  `projectedSeconds` via `formatDurationHm`, and when `projectedIncomplete` show an
  **approximate/incomplete indicator** beside it ("≥", a warning icon + tooltip, role-named
  classes — FR-015); pass the selected driver's `startIndex` into the assign dialog. Drop the
  `currentTourTotalS` prop (unused).
- `use-assign-driver` + `assign-driver-dialog`: send `start_index`.
- `result-summary.tsx` / `optimize.tsx`: pass the tour id to the driver list (already have
  `result.id`); stop threading `currentTourTotalS`.

### R9 — Performance with clarity (Constitution V) — dedup + capped concurrency (FR-014)

The drivers endpoint issues OpenStreet `/route` calls — worst case
`O(drivers × (priorTours + startCandidates))` legs. Per the clarification (spec 2026-07-02),
this is handled **now**, not deferred:

- **Deduplicate** to the distinct leg set (identical warehouse/return/between legs across
  drivers requested once) — R4 phase 1.
- **Fetch concurrently under a cap** — a bounded `Http::pool` batch (configurable concurrency
  cap) so the response is fast without flooding / rate-limiting the routing API (`LIMIT_REACHED`)
  — R4 phase 2. Chunk the distinct set to the cap.
- **Compute over pre-fetched durations** — the estimator does map lookups, no I/O — R4 phase 4.
- Net at real scale (a handful of drivers, ≤10 stops/tour, few prior tours/day): a small
  distinct-leg set fetched in one or few capped waves. The cap keeps peak concurrency bounded
  (testable — SC-006).

### R10 — Unknown vs zero, end-to-end (FR-009 / FR-010 / FR-015)

- A failed `/route` leg → its duration is **unknown**; it is **logged** and contributes **0** to
  a **best-effort** projected day, which is **flagged `projected_incomplete`** (FR-015) —
  "at least this long, possibly more." The whole figure is **not** hidden (this supersedes 012's
  null-propagation for the projected day; the per-leg unknown state is still distinct from zero).
- Start selection tolerates unknown legs: choose the **minimum known** candidate; if all are
  unknown, pick deterministically (lowest index) so assignment still proceeds (and that driver's
  figure is flagged incomplete).
- **Coincident** warehouse/stop points yield a real **0** with no API call — distinct from a
  failed (unknown) leg, and never sets the incomplete flag.
