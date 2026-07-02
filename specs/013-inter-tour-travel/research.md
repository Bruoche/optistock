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
- **Clean schema, no migration crutch** (pre-release, no production data at risk): the
  migration creates `warehouses` and adds `warehouse_id` as **NOT NULL with no DB default**
  and no backfill row. Demo warehouses are created by the seeder. A developer runs
  `migrate:fresh --seed`. Every driver-create path (factory, seeder, any future form) sets
  `warehouse_id` explicitly, so a missing assignment **fails loudly** rather than being masked
  by a default (constitution IV) — the best end-state for production, chosen because there is
  no legacy data forcing a softer path.
- **Alternatives**: single global warehouse (rejected — clarification chose multiple, each
  driver one); nullable link with graceful degrade (rejected — clarification made it
  mandatory); a defaulted/backfilled column (rejected — no prod data, so a clean NOT-NULL
  column is better than a runtime default that could mask a bug). See spec Clarifications 2026-07-02.

### R2 — `driver_tour` gains start/end coordinates + a sequence

- Add `start_latitude`, `start_longitude`, `end_latitude`, `end_longitude` decimal(10,7),
  and **`sequence`** unsignedInteger (the driver's ordering of the day's assigned tours —
  the max sequence for a driver+date is their current latest tour; a future re-ordering
  feature rewrites `sequence`). The existing `(driver_id, date)` index already serves the
  per-day ordered lookup.
- **All NOT NULL** (pre-release, no production data): the assign path always sets the start/end
  coordinates + sequence, so there is no reason to soften the columns to nullable. The estimator
  can therefore **rely on every assigned tour having concrete start/end coordinates** — no
  defensive null-coord branch (which existed only to tolerate hypothetical legacy rows). A
  developer runs `migrate:fresh` for the added columns.
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
  3. **Populates a per-request duration map** (`legKey → ?int`); each pooled response is mapped
     to a duration via the **same `OpenStreetRouteClient`** — both the **request building**
     (base URL, `origin`/`destination`/`mode`/`key` params, timeout) **and** the response→leg
     parsing are shared (N2), so the pool path introduces **zero** duplicated request/response
     logic; failure → null (logged per leg).
  4. The travel-time consumers then read durations from this pre-filled map (a `durationBetween`
     lookup is a map hit; a coincident pair returns 0 without a call).
- **Reuse, not duplication** (N2): `traceLeg` still serves single-leg needs; the pool path reuses
  the client's **request builder + response→leg mapping** (both factored out on
  `OpenStreetRouteClient`, e.g. `legRequestParams()` + `mapResponseToDuration()`), so URL/params/
  key/timeout live in exactly one place. `TourGeometryService::trace` stays **untouched** (pure,
  002 whole-tour geometry). This is the "Trace route function called on each valid start / between
  each tour," now dedup+capped-concurrent per the clarification.

### R5 — Two clean, separate concerns: start selection vs. the pure day total

Selecting a start and summing a day are **separate responsibilities** (spec FR-016). Keeping
them apart makes the day-total reusable for later "view a driver's assigned tours" screens that
have no prospective/incoming tour to place, and keeps each function single-purpose (constitution III).

- **`TourStartSelector`** (injected `TravelTimeService`) — the *only* place that picks a start.
  `select(Coordinate $incoming, Tour $candidate, ?string $mode): { start_index, start, end }`:
  for each `startCandidates()` coordinate, `durationBetween($incoming → start)`; pick the
  **minimum known** (deterministic tie-break: lowest index; all-unknown → lowest index);
  `end = endStopForStart(start)`. The incoming point is passed **in** (the caller decides it is
  the warehouse for the first tour of the day, or the prior tour's end otherwise) — the selector
  never reaches for prior tours itself.

- **`WorkdayEstimator`** (injected `TravelTimeService`) — the **pure day total**, no selection.
  `total(Coordinate $warehouse, list<TourSegment> $segments, ?string $mode): { projected_duration_s: int, incomplete: bool }`
  where a **`TourSegment`** is a resolved `{ Coordinate start, Coordinate end, ?int duration_s }`.
  It sums the connecting legs over the **already-resolved coordinates** it is handed —
  `durationBetween(W → segments[0].start)` + Σ `durationBetween(segments[i].end → segments[i+1].start)`
  + `durationBetween(segments.last.end → W)` — plus Σ each segment's `duration_s`. It performs
  **no start selection** (segments already carry start/end). **Any unknown value → 0 + `incomplete`**:
  a failed connecting leg **or** a segment with `duration_s === null` (a prior or candidate tour
  with unknown own duration) both set the flag (FR-009/FR-015, **N1**). Never nulls the whole figure.

- **Composition** (drivers endpoint): build prior `TourSegment`s from `driver_tour` (stored
  start/end + each tour's total); determine the incoming point (last prior end, or warehouse if
  none); `TourStartSelector::select` the candidate → append its resolved `TourSegment`; call
  `WorkdayEstimator::total`. Selection happens **beforehand**, the total stays clean.

- **Reuse**: a later "assigned tours of a driver" view calls `WorkdayEstimator::total` with the
  stored segments and **no selector at all** — exactly the reusable shape the user asked for.

- **Between-tour legs are recomputed every call**, never stored — keeps the figure correct once
  a future feature re-orders a driver's tours (only per-tour start/end coords + `sequence` persist).

- Both are pure over an injected `TravelTimeService` → unit-testable with a fake.

### R6 — `GET /api/tour/drivers` now takes the tour + returns the chained figure

- Request gains a required **`tour`** id (the persisted candidate). `AvailableDriversRequest`
  validates it exists **and is owned** by the user (404 on a foreign id, mirroring the assign
  guard); the controller loads the tour + stops for `startCandidates`/`loop`/total.
- Per available driver: load the **warehouse** coordinate + **prior tours for the date**
  (`driver_tour` rows ordered by `sequence`, with each tour's total — one grouped aggregate, M1)
  as resolved `TourSegment`s; determine the incoming point (last prior end, or warehouse);
  `TourStartSelector::select` the candidate → append its `TourSegment`; `WorkdayEstimator::total`.
  Emit per driver:
  - `warehouse_name` (shown on the driver row — "where they come from"),
  - `projected_seconds` (the **best-effort full chain**, `int`; any unknown counts 0),
  - `projected_incomplete` (`bool` — any unknown leg **or** unknown tour duration → flagged, FR-015),
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

- **Any unknown value → 0 + `incomplete`** (N1): a failed `/route` connecting leg **or** a tour
  segment with `duration_s === null` (a prior or candidate tour whose own road time was never
  resolved) is **logged** and contributes **0** to a **best-effort** projected day, which is
  **flagged `projected_incomplete`** (FR-015) — "at least this long, possibly more." The whole
  figure is **not** hidden (supersedes 012's null-propagation for the projected day; the per-value
  unknown state is still distinct from zero). Prior-tour null durations flag the day identically
  to candidate null durations — the flag means the same thing everywhere.
- Start selection tolerates unknown legs: choose the **minimum known** candidate; if all are
  unknown, pick deterministically (lowest index) so assignment still proceeds (and that driver's
  figure is flagged incomplete).
- **Coincident** warehouse/stop points yield a real **0** with no API call — distinct from a
  failed (unknown) leg, and never sets the incomplete flag.
