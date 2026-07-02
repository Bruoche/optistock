# Implementation Plan: Inter-Tour Travel Time

**Branch**: `013-inter-tour-travel` | **Date**: 2026-07-02 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/013-inter-tour-travel/spec.md`, building on
features 001 (optimization), 002 (road-accurate per-leg tracing), 006/011 (driver list +
date filtering) and 012 (persisted tours/stops + `driver_tour` assignment + projected hours).

## Summary

Turn 012's "sum of tour durations" projected figure into a **full chained workday** and add
the anchoring it needs:

1. **Warehouse.** New `warehouses` table; every driver gets a **mandatory** warehouse
   (many-to-one), whose name shows on the driver row.
2. **Start/end stop per assignment.** A tour exposes its **valid start points** (loop → any
   stop; one-way → its two endpoints) and **deduces the end** from a chosen start. The
   `driver_tour` association stores the chosen **start/end coordinates** plus a **`sequence`**
   (the driver's tour order for the day — the max is their current latest tour).
3. **Chained estimate.** A driver's projected day = `warehouse → first tour start` + Σ tour
   totals + Σ `prev end → next start` between-legs + `last end → warehouse`. Connecting legs
   use the 002 `/route` client and are **recomputed every time** (never stored) so a future
   re-ordering feature stays correct. A leg that fails to route contributes 0 and **flags the
   figure approximate/incomplete** (best-effort lower bound — clarified) rather than hiding it.
4. **Start selection is automatic + reused.** For each candidate start the `/route` client is
   called from the incoming point (warehouse, or the prior tour's end); the **closest** is the
   start. `GET /api/tour/drivers` returns each driver's chained figure **and** its selected
   start, so the assign call reuses the start rather than recomputing it.
5. **Routing load is bounded.** The drivers endpoint **deduplicates** the distinct travel legs
   across all drivers and fetches them in a **capped concurrent batch** (bounded `Http::pool`),
   then computes each driver's chain from the pre-fetched durations (clarified — FR-014).

The design keeps the OpenStreet call behind a thin duration service and the chain math in a
pure estimator, both reusable by the later re-ordering feature.

## Technical Context

**Stack**: Laravel 12 (PHP) + React 19 + Inertia + Tailwind v4 + shadcn/ui; MySQL/SQLite;
PHPUnit (`Tests\TestCase` + `RefreshDatabase`) + Vitest/Testing Library.

**Existing pieces reused (unchanged)**:
- `OpenStreetRouteClient::traceLeg(origin, destination, mode)` — one leg's road
  `distance_m`/`duration_s`, throws `TourGeometryException` on failure.
- `TourGeometryService::trace` stays **pure** (002 whole-tour geometry); not touched.
- `tours.travel_duration_s` nullable + `Tour::total_duration_s` null-propagating accessor (012).
- `driver_tour` unique `tour_id` (one driver per tour), `(driver_id,date)` index.

**Project Type**: web app (Laravel + React SPA).

**Performance/Scale**: the drivers endpoint becomes routing-heavy — worst case
`O(drivers × (priorTours + startCandidates))` `/route` legs — mitigated by **deduplicating the
distinct leg set + a capped concurrent `Http::pool` batch** (research R4/R9, FR-014); real scale
is a handful of drivers, ≤10 stops/tour, few prior tours/day.

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — every new surface is covered: `Tour::startCandidates` /
  `endStopForStart` (Unit, loop + one-way); `TravelTimeService` failure→null + distinct-leg
  dedup + capped pool (Unit, faked client, peak-concurrency assertion — SC-006);
  `WorkdayEstimator` chain + best-effort-on-failure + `incomplete` flag + start selection +
  tie-break (Unit, fake travel times); `warehouses`/`Warehouse` + mandatory `warehouse_id`
  (migration + factory/seeder); `GET /api/tour/drivers` (Feature, `Http::fake` — requires
  `tour`, ownership 404, `warehouse_name` + `projected_seconds` + `projected_incomplete` +
  `start_index`, a failed leg → best-effort + flag, no duplicate leg calls); assign (Feature —
  `start_index` legality, start/end/sequence persisted, sequence increments, idempotent);
  frontend (driver-list shows warehouse + projected + incomplete indicator + passes
  `start_index`; assign hook/dialog send it). PASS.
- **II/III. Readable & Simple** — one thin single-leg duration service over the existing
  `traceLeg`; one pure estimator with a single responsibility (chain a day, pick a start);
  shape queries live on `Tour` where the `loop`/`stops` data already is; no change to the
  delicate optimize/cache/broadcast code. PASS.
- **IV. Robustness** — mandatory warehouse FK (`restrictOnDelete`); a failed `/route` leg is
  **caught + logged `warning`**, then counts 0 toward a **best-effort** figure that is
  **flagged `projected_incomplete`** so the manager is told it may understate travel (FR-009/
  FR-015) — never a silent exact 0; genuine zero (coincident points) kept distinct (FR-010);
  `start_index` is server-selected but **still validated** as a legal start on assign;
  drivers/assign both enforce **tour ownership** (404). Migration adds columns safely on the
  already-shipped `drivers`/`driver_tour` tables (default warehouse backfill;
  nullable-for-migration coords the app always populates). PASS.
- **V. Performance with Clarity** — the routing load is bounded by **deduplicating the distinct
  leg set** and fetching it in a **capped concurrent `Http::pool` batch** (FR-014), reusing the
  route client's response parsing (no duplicated logic); the cap prevents flooding /
  rate-limiting; the estimator then does pure map lookups. Peak concurrency is asserted in a
  test (SC-006). PASS.
- **VI. Consistent, Reusable Styling** — the warehouse name + projected figure reuse existing
  muted/role-named text classes and `formatDurationHm`; no new colors, no raw hex. PASS.

No violations. (Complexity Tracking omitted.)

## Decisions

Full rationale + alternatives in [research.md](research.md); condensed:

- **D1 — `warehouses` + mandatory `drivers.warehouse_id`.** Multiple warehouses, each driver
  exactly one (FR-001), `restrictOnDelete`. Migration seeds a default and adds the column
  defaulting to it so the shipped `drivers` table migrates NOT NULL; the DB default is a
  legacy-row convenience — app paths set it explicitly. `Driver belongsTo Warehouse`. (R1)
- **D2 — `driver_tour` gains `start_*`/`end_*` coordinates + `sequence`.** Coordinates (not a
  stop FK) keep the association self-contained + edit-proof; `sequence` orders the day
  (max = latest) for future re-ordering. Coords nullable **only** for migration safety; the
  assign path always sets them. (R2)
- **D3 — `Tour::startCandidates()` + `endStopForStart()`.** Loop → all stops, end = start;
  one-way → the two endpoint positions, end = the opposite endpoint. The "Route returns its
  valid points" the user asked for; reused by drivers (selection) + assign (deduction). (R3)
- **D4 — `TravelTimeService`: dedup + capped concurrent batch (FR-014).** Two-phase: collect
  the **distinct** leg set (keyed by rounded from/to + mode) across all drivers, fetch
  outstanding legs via a **bounded `Http::pool`** (configurable concurrency cap, chunked) into a
  per-request duration map, then `durationBetween(from,to,mode)` is a map lookup (coincident→0,
  failure→null, logged). The pool path **reuses `OpenStreetRouteClient`'s response→leg parsing**
  (factored out so single-call and pooled paths share it — no dup logic); `TourGeometryService`
  untouched. (R4/R9)
- **D5 — `WorkdayEstimator` (pure).** Chains warehouse → prior tours (fixed saved start/end) →
  candidate (appended last), selects the candidate start as the **closest known** valid start
  to the incoming point, sums legs + tour totals. A **failed leg counts 0 and sets `incomplete`**
  (best-effort lower bound — FR-009/FR-015); returns
  `{ projected_duration_s: int, incomplete: bool, start_index, start, end }`. Between-legs
  recomputed, never stored. (R5)
- **D6 — `GET /api/tour/drivers` takes `tour`, returns the chain + selected start.** Requires
  an owned `tour` id (404 otherwise); per driver emits `warehouse_name`, `projected_seconds`
  (`int`, best-effort), `projected_incomplete` (`bool`), `start_index`. `assigned_seconds` +
  the client-side add step are removed — the server figure is complete. (R6, contract
  `driver-workday.md`)
- **D7 — `POST assign` takes `start_index`.** Reuses the selected start (no recompute) but
  **validates** it's a legal start position; resolves start Stop, deduces end, writes
  start/end coords + `sequence = prevMax+1`; idempotent `sync` unchanged. (R7, contract
  `tour-assignment.md`)
- **D8 — Frontend.** `Driver` gains `warehouseName`/`projectedSeconds`/`projectedIncomplete`/
  `startIndex` (drops `assignedSeconds`); driver-list shows the warehouse + the projected figure
  with an **approximate/incomplete indicator** when `projectedIncomplete` (FR-015), and passes
  `start_index` to the assign hook/dialog; `result-summary` passes the tour id and stops
  threading `currentTourTotalS`. (R8)
- **D9 — Unknown, zero, and the accuracy flag end-to-end.** Failed leg → logged, counts 0 in a
  **best-effort** figure flagged `projected_incomplete` (never hidden, never a silent exact 0);
  coincident points → real 0 (no flag); selection tolerates unknown legs (min known /
  deterministic all-unknown). (R10)

## Project Structure (feature-specific)

Backend — **new**:
- `database/migrations/2026_07_02_000001_add_warehouses_and_assignment_geometry.php` —
  create `warehouses` (+ seed default), add `drivers.warehouse_id`, add `driver_tour`
  `start_*`/`end_*`/`sequence` (+ backfill).
- `app/Models/Warehouse.php` — `#[Fillable(['name','latitude','longitude'])]`, float casts,
  `drivers(): HasMany`.
- `app/Services/TravelTimeService.php` — collects the distinct leg set, fetches it via a
  **capped `Http::pool` batch** into a per-request map, exposes
  `durationBetween(Coordinate,Coordinate,?string): ?int` (map lookup; coincident→0, failure→null
  logged). Reuses the route client's response→leg parsing (FR-014, R4).
- `app/Services/WorkdayEstimator.php` (+ small `WorkdayEstimate` value object / `CandidateTour`
  input struct) — the pure chain calculation returning `projected_duration_s` + `incomplete`
  flag + selected start (R5).
- `database/factories/WarehouseFactory.php`.

Backend — **change**:
- `app/Models/Driver.php` — `warehouse(): BelongsTo`; `available` scope eager-loads
  `warehouse`; remove `committedSecondsForDate` (superseded by the estimator).
- `app/Models/Tour.php` — `startCandidates()`, `endStopForStart()`; widen `drivers()` pivot
  (`start_*`, `end_*`, `sequence`).
- `app/Http/Controllers/DriverController.php` — load the owned candidate tour; prime
  `TravelTimeService` with the distinct leg set (dedup + capped pool); per driver run
  `WorkdayEstimator`; emit `warehouse_name` / `projected_seconds` / `projected_incomplete` /
  `start_index`.
- `app/Services/OpenStreetRouteClient.php` — factor the response→leg mapping into a reusable
  method so the pooled path and `traceLeg` share it (no dup parsing).
- `app/Http/Requests/AvailableDriversRequest.php` — require `tour` (exists + **owned**, 404).
- `app/Http/Controllers/TourAssignmentController.php` — resolve start Stop from `start_index`,
  deduce end, compute `sequence`, persist start/end coords + sequence via `sync`.
- `app/Http/Requests/AssignTourRequest.php` — require `start_index` + validate it is a legal
  start position for the bound tour.
- `database/factories/DriverFactory.php` + `database/seeders/DriverDemoSeeder.php` — set
  `warehouse_id` (factory: `Warehouse::factory()`; seeder: seeded warehouses).

Frontend — **change**:
- `resources/js/types/tour.ts` — `Driver`: `warehouseName`, `projectedSeconds: number`,
  `projectedIncomplete: boolean`, `startIndex`; drop `assignedSeconds` + the
  `projectedSeconds()` helper.
- `resources/js/hooks/use-tour-drivers.ts` — send `&tour=<id>`; map the new fields.
- `resources/js/components/tour/driver-list.tsx` — show the warehouse name + projected figure
  with an approximate/incomplete indicator when `projectedIncomplete` (FR-015); pass the
  selected driver's `startIndex` to the dialog; drop `currentTourTotalS`.
- `resources/js/hooks/use-assign-driver.ts` + `components/tour/assign-driver-dialog.tsx` —
  send `start_index`.
- `resources/js/components/tour/result-summary.tsx` — pass the tour id to `DriverList`; stop
  threading `currentTourTotalS`.

Tests: `tests/Unit/TourTest.php` (start candidates/end), `TravelTimeServiceTest.php` (dedup =
distinct call count, capped peak concurrency — SC-006, failure→null),
`WorkdayEstimatorTest.php` (chain, best-effort + `incomplete` on failure, selection/tie-break);
`tests/Feature/DriverAvailabilityTest.php` (extend — `tour`, ownership,
warehouse/projected/`projected_incomplete`/start_index, no duplicate leg calls),
`TourAssignmentTest.php` (extend — start_index + persistence + sequence); frontend
`driver-list.test.tsx` (warehouse + incomplete indicator), `assign-driver-dialog.test.tsx`
(extend). Warehouse factory/seeder covered via the availability + assignment tests.

Out of scope (designed-for, not built): a warehouse-management UI; re-ordering a driver's
tours (the `sequence` + stored start/end coords make it a clean follow-up); per-driver
contracted daily-hours limits.

## Flow (presentation → assign)

1. Optimize → geometry finalizes `travel_duration_s` (012); a persisted `tour` id is in hand.
2. Presentation: `GET /api/tour/drivers?mode&date&tour=<id>`. The server dedups + capped-pool
   fetches the distinct legs, then per eligible driver runs `WorkdayEstimator` (warehouse →
   prior tours by `sequence` → candidate appended), returning `warehouse_name`,
   `projected_seconds` (best-effort), `projected_incomplete`, and the selected `start_index`.
3. Each row shows the driver, their warehouse, and the chained projected day (marked
   approximate when `projected_incomplete`).
4. Click a driver → confirm → `POST /api/tour/{id}/assign { driver_id, date, start_index }`.
   Server validates the start is legal, deduces the end, writes start/end coords +
   `sequence = prevMax+1`, and returns success → `reset()`.

## API contracts (this run)

- `GET /api/tour/drivers?mode&date&tour` — **`tour` now required**; each driver gains
  `warehouse_name`, `projected_seconds` (`int`, best-effort), `projected_incomplete` (`bool`),
  `start_index`; `assigned_seconds` removed. Foreign/unknown `tour` → 404. Server dedups + capped
  pools the routing legs (FR-014). See `contracts/driver-workday.md`.
- `POST /api/tour/{tour}/assign` — body gains **`start_index`**; persists start/end
  coordinates + `sequence`. See `contracts/tour-assignment.md`.

## Design Artifacts (this run)

- `research.md` — data-flow slice + decisions R1–R10 (warehouse model, association geometry,
  Tour shape queries, travel-time service, estimator, endpoint changes, unknown/zero, perf).
- `data-model.md` — `warehouses`, `drivers.warehouse_id`, `driver_tour` start/end + `sequence`,
  Tour accessors, new services, forward-compat.
- `contracts/driver-workday.md` — the chained drivers endpoint.
- `contracts/tour-assignment.md` — the start-aware assign endpoint.
- `quickstart.md` — warehouse link, start/end, closest selection, chained figure, persistence,
  unknown-vs-zero checks.

---

Generated by speckit.plan on 2026-07-02
