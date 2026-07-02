# Quickstart: Inter-Tour Travel Time

**Feature**: 013-inter-tour-travel

Manual + automated checks that the chained workday, start/end anchoring, and warehouse link
behave per spec.

## Setup

1. `php artisan migrate` — creates `warehouses`, seeds a default, adds mandatory
   `drivers.warehouse_id`, adds `driver_tour` start/end + `sequence`.
2. `php artisan db:seed --class=DriverDemoSeeder` — demo drivers now carry warehouses.
3. `Http::fake()` the OpenStreet `/route` endpoint in tests with per-leg durations.

## Warehouse link (FR-001)

- [ ] Every driver has exactly one `warehouse_id`; it is not nullable and a driver cannot be
      created without one (factory/seeder set it).
- [ ] A warehouse with drivers cannot be deleted (`restrictOnDelete`).
- [ ] The driver row on the presentation phase shows the **warehouse name**.

## Start / end stop (FR-004–FR-006)

- [ ] **Looping tour**: `Tour::startCandidates()` returns all stops; `endStopForStart(s)` = `s`.
- [ ] **One-way tour**: `startCandidates()` returns only the first + last position stops;
      `endStopForStart(firstEnd)` = the last end, and vice-versa; an interior position is
      rejected by `AssignTourRequest`.

## Closest start selection (FR-007, US3)

- [ ] Fake two candidate legs with clearly different durations from the incoming point; the
      drivers payload `start_index` is the **shorter** one.
- [ ] First tour of the day → incoming point is the **warehouse**; a later tour → incoming
      point is the driver's **previous tour's end** coordinate.
- [ ] One-way tour: selecting the nearer endpoint as start makes the far endpoint the end.

## Chained projected workday (FR-002, US1, SC-001/SC-002)

- [ ] Driver with **no** prior tours: `projected_seconds` =
      `W→start + tourTotal + end→W` (all three legs present).
- [ ] Driver with prior tours: `projected_seconds` = full chain across prior tours + the
      candidate appended last, with every between-leg and both warehouse legs.
- [ ] The figure is **≥** the plain sum of tour totals, and strictly greater when any
      connecting leg is non-zero.

## Assignment persists start/end + sequence (FR-012, FR-013)

- [ ] `POST assign { …, start_index }` writes `start_*`/`end_*` coordinates matching the
      chosen stop + its deduced end, and `sequence = prevMax + 1`.
- [ ] Assigning a second tour to the same driver+date gets the next `sequence`.
- [ ] The assign endpoint does **not** re-run start selection (it uses the given index).

## Best-effort figure + inaccuracy flag (FR-009/FR-010/FR-015, SC-005)

- [ ] A `/route` leg failing → that leg logged `warning`, counts **0** in the projected total,
      and the driver's `projected_incomplete` is **true** → the row shows the figure marked
      approximate/incomplete (never hidden, never a silent exact total).
- [ ] A driver whose every leg routed → `projected_incomplete` **false**, no indicator.
- [ ] A warehouse coincident with the start stop → that leg is a genuine **0** (no API call),
      and does **not** set the incomplete flag.

## Routing load: dedup + capped concurrency (FR-014, SC-006)

- [ ] Two drivers sharing a warehouse trigger the shared warehouse/return leg **once** (assert
      the faked `/route` call count = the distinct leg count, not the naive per-driver total).
- [ ] Peak concurrent in-flight `/route` requests never exceed the configured cap.

## Regression

- [ ] `GET /api/tour/drivers` requires `tour`; a foreign tour id → 404.
- [ ] Assign still idempotent on the unique `tour_id`; ownership + eligibility still enforced.
