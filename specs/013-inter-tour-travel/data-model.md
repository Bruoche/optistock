# Data Model: Inter-Tour Travel Time

**Feature**: 013-inter-tour-travel | **Date**: 2026-07-02

New table `warehouses`; a mandatory `drivers.warehouse_id`; new start/end coordinate +
`sequence` columns on the existing `driver_tour` association; two shape accessors on
`Tour`. No change to `tours`/`stops` columns.

## `warehouses` (new)

| Column       | Type            | Notes                                             |
|--------------|-----------------|---------------------------------------------------|
| `id`         | bigint PK       |                                                   |
| `name`       | string          | Shown on the driver info ("where they come from") |
| `latitude`   | decimal(10,7)   | Matches `stops` precision → comparable as a point |
| `longitude`  | decimal(10,7)   |                                                   |
| timestamps   |                 |                                                   |

Seeded with a **"Default warehouse"** row inside the migration (used to backfill existing
drivers and as demo data).

## `drivers` (change)

| Column         | Type       | Notes                                                        |
|----------------|------------|--------------------------------------------------------------|
| `warehouse_id` | bigint FK  | **Mandatory** (FR-001), `restrictOnDelete`. Added defaulting to the seeded default warehouse so the already-shipped table migrates with the column NOT NULL; the DB default is a legacy-row convenience only — app paths always set it explicitly. |

- Relationship: `Driver belongsTo Warehouse`; `Warehouse hasMany Driver` (many-to-one).
- `DriverFactory` + `DriverDemoSeeder` set `warehouse_id` explicitly (factory: a
  `Warehouse::factory()`; demo seeder: the default/seeded warehouses).

## `driver_tour` (change — the assignment association)

| Column            | Type            | Notes                                                             |
|-------------------|-----------------|-------------------------------------------------------------------|
| `start_latitude`  | decimal(10,7)   | The chosen start stop's coordinate for this assignment.           |
| `start_longitude` | decimal(10,7)   |                                                                   |
| `end_latitude`    | decimal(10,7)   | The deduced end stop's coordinate (loop = start; one-way = other endpoint). |
| `end_longitude`   | decimal(10,7)   |                                                                   |
| `sequence`        | unsignedInteger | The driver's ordering of the day's tours; max per (driver,date) = current latest. Default `0`; existing rows backfilled per (driver,date) by `id`. |

- The four coordinate columns are **nullable at the DB level solely for migration safety**
  on a possibly-populated `driver_tour` (no source to backfill a historical start/end). The
  **assign path always populates them**; the estimator treats a null (legacy) row's connecting
  legs as unknown rather than failing.
- Existing `(driver_id, date)` index unchanged — serves the ordered per-day lookup.
- Pivot access via `Driver::tours()` / `Tour::drivers()` gains
  `->withPivot('date', 'start_latitude', 'start_longitude', 'end_latitude', 'end_longitude', 'sequence')`.

## `Tour` (accessors — shape queries)

- **`startCandidates(): Collection<Stop>`** — looping → all `stops`; one-way → the stops at
  the min and max `position` (the two endpoints; interior stops excluded).
- **`endStopForStart(Stop $start): Stop`** — looping → `$start`; one-way → the opposite
  endpoint of `$start`.
- Reuses the existing ordered `stops()` relation (`orderBy('position')`).

## Models

- **`Warehouse`** (new): `#[Fillable(['name','latitude','longitude'])]`; casts lat/lng to
  float; `drivers(): HasMany`. `WarehouseFactory` (new).
- **`Driver`** (change): `warehouse(): BelongsTo`; the `available` scope eager-loads
  `warehouse` for the name + coordinate. `committedSecondsForDate` is **superseded** by the
  `WorkdayEstimator` path and removed from the drivers payload (the chained figure replaces it).
- **`Tour`** (change): the two shape accessors above; `drivers()` pivot widened.

## Services (new)

- **`TravelTimeService`** — primes a per-request duration map by collecting the **distinct** leg
  set and fetching it via a **capped `Http::pool` batch** (FR-014), then serves
  `durationBetween(Coordinate $from, Coordinate $to, ?string $mode): ?int` as a map lookup
  (coincident→0, failure→null logged). Reuses `OpenStreetRouteClient`'s response→leg parsing.
- **`WorkdayEstimator`** — `estimate(Coordinate $warehouse, array $priorTours, CandidateTour $candidate, ?string $mode): WorkdayEstimate`
  returning `{ projected_duration_s: int, incomplete: bool, start_index: int, start: Coordinate, end: Coordinate }`
  — best-effort total with an `incomplete` flag when a leg failed (research R5). Pure over an
  injected `TravelTimeService`.

## Forward-compatibility

- `sequence` + per-tour start/end coordinates (not stored between-legs) let a later
  **re-ordering** feature rewrite the order and get correct travel by recomputing legs.
- Coordinates on the association (not stop FKs) keep assignments valid across stop edits.
- Multiple `warehouses` + the mandatory FK support a future warehouse-management UI without
  schema change.
