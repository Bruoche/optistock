---
description: "Task list for Inter-Tour Travel Time"
---

# Tasks: Inter-Tour Travel Time

**Input**: Design documents from `specs/013-inter-tour-travel/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md,
contracts/driver-workday.md, contracts/tour-assignment.md

**Tests**: Included — constitution I mandates tests; plan Constitution Check lists them.

**Organization**: The warehouse schema + travel-time/estimator machinery are shared by all
three P1 stories, so they are **foundational** (Phase 2). Stories then layer on:

- **US1 (P1)** — Projected day includes travel to/from/between tours (the chained figure). 🎯 MVP
- **US2 (P1)** — Start and end stops are set (recorded) when a tour is assigned.
- **US3 (P1)** — Start stop is chosen as the closest valid stop (selection correctness + round-trip).

**Analyze findings encoded** (from `/speckit-analyze`): **H1** null tour-total rule → T014;
**H2** chunk-to-cap pool + per-leg failure logging → T011/T013; **M1** single grouped
prior-tour totals (no N+1) → T017; **M2** shared coordinate-key precision → T009/T011;
**M5** OpenStreetRouteClient parser extraction preserves 002 → T010; **C1** FR-012 reworded
(start/end stops stored, legs recomputed); **T1** SC-006 asserted via chunk-count proxy → T013;
**U1** all `start_index` plumbing consolidated into US1 payload + US2 assign wiring so the assign
UI is never broken mid-stream (US3 is tests-only). **L1/L3** resolved by schema (no prod data →
`driver_tour` start/end + `drivers.warehouse_id` are **NOT NULL, no default/backfill**; fresh
migrate) → T002, so no defensive legacy-null branch is needed; **L2** single-stop edge → T012.

## Path Conventions

Web app (Laravel + React SPA): backend under `app/`, `database/`, `routes/`, `tests/`;
frontend under `resources/js/`.

---

## Phase 1: Setup

- [ ] T001 Add a routing-concurrency cap config value `openstreet.route_pool_cap` (default e.g. 5) in `config/services.php`, and confirm the injected HTTP client factory (`Illuminate\Http\Client\Factory`) supports `pool()`. No new dependencies.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Warehouse schema + driver link, assignment geometry columns, Tour shape queries, and the shared travel-time service — everything all three stories build on. Does not touch the async optimize/cache/broadcast flow.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

### Schema, models, factories, seeders

- [ ] T002 Migration `database/migrations/2026_07_02_000001_add_warehouses_and_assignment_geometry.php`: create `warehouses` (`name`, `latitude` decimal(10,7), `longitude` decimal(10,7), timestamps); add `drivers.warehouse_id` FK **NOT NULL, no DB default**, `restrictOnDelete`; add `driver_tour` `start_latitude`/`start_longitude`/`end_latitude`/`end_longitude` decimal(10,7) **NOT NULL** + `sequence` unsignedInteger **NOT NULL**. No backfill / no default crutch — pre-release, no production data, so a fresh migrate is expected (run `php artisan migrate:fresh --seed` in dev). Every driver-create path sets `warehouse_id` explicitly, so a missing one fails loudly (constitution IV).
- [ ] T003 [P] Create `Warehouse` model in `app/Models/Warehouse.php` — `#[Fillable(['name','latitude','longitude'])]`, float casts for lat/lng, `drivers(): HasMany`.
- [ ] T004 [P] Create `WarehouseFactory` in `database/factories/WarehouseFactory.php` (name + realistic lat/lng).
- [ ] T005 Update `app/Models/Driver.php`: add `warehouse(): BelongsTo`; eager-load `warehouse` in the `available` scope; **remove** `committedSecondsForDate` (superseded by `WorkdayEstimator`).
- [ ] T006 [P] Update `database/factories/DriverFactory.php`: set `warehouse_id` via `Warehouse::factory()` in `definition()` so every factory driver has a warehouse.
- [ ] T007 [P] Update `database/seeders/DriverDemoSeeder.php`: create/reuse a couple of demo `warehouses` (idempotent by name) and assign one to each demo driver.
- [ ] T008 Update `app/Models/Tour.php`: add `startCandidates(): Collection<Stop>` (looping → all `stops`; one-way → the min- and max-`position` stops only) and `endStopForStart(Stop $start): Stop` (looping → same stop; one-way → opposite endpoint); widen `drivers()` pivot with `withPivot('date','start_latitude','start_longitude','end_latitude','end_longitude','sequence')`.

### Shared travel-time machinery

- [ ] T009 [P] Add a shared coordinate-key helper reusing `CoordinateNormalizer::PRECISION` (5) — factor `TourRecorder::coordinateKey` into a reusable location (e.g. a static on `Coordinate` or a small helper) so `TravelTimeService` dedup keys and coincident-point detection use the same rounding (M2). Update `TourRecorder` to use it (behavior unchanged).
- [ ] T010 Refactor `app/Services/OpenStreetRouteClient.php`: extract the response→leg mapping (`mapToLeg` + `isSuccess`) into a reusable method the pooled path can call, **without changing `traceLeg` behavior** (M5). Existing 002 geometry tests are the regression guard.
- [ ] T011 Create `app/Services/TravelTimeService.php`: given a set of `(from, to, mode)` legs, **collect the distinct set** (keyed via T009 helper), fetch outstanding legs with a **capped, chunked `Http::pool`** (chunk size = `openstreet.route_pool_cap` so peak concurrency ≤ cap — H2), map each pooled response via T010's parser into a per-request `legKey → ?int` duration map (**each failed leg logged `warning` with context** — H2/constitution IV; coincident points → genuine `0`, no call), and expose `durationBetween(Coordinate $from, Coordinate $to, ?string $mode): ?int` as a map lookup. `TourGeometryService::trace` untouched.

### Foundational tests

- [ ] T012 [P] Unit `tests/Unit/TourTest.php`: `startCandidates` (all stops for loop; only the two endpoints for one-way; **single-stop tour → that one stop**) and `endStopForStart` (same stop for loop; opposite endpoint for one-way; single-stop → same stop).
- [ ] T013 [P] Unit `tests/Unit/TravelTimeServiceTest.php` (`Http::fake`): distinct legs requested once (assert call count = distinct count, not naive total); the distinct set is **chunked into ⌈distinct/cap⌉ pool batches, each issued with ≤ cap requests** (observable proxy for the concurrency cap under `Http::fake` — SC-006); a failed leg → `null` + a logged warning; coincident points → `0` with no call.

**Checkpoint**: Warehouse link, assignment-geometry columns, Tour shape queries, and the dedup+capped travel-time service exist and are tested.

---

## Phase 3: User Story 1 — Projected day includes travel to/from/between tours (Priority: P1) 🎯 MVP

**Goal**: Each driver row shows the full chained workday (warehouse → tours → warehouse, with inter-tour travel), best-effort with an approximate flag when a leg fails.

**Independent Test**: For a driver with a warehouse and ≥1 tour on the date, the projected figure equals `W→firstStart + Σ tour totals + Σ between-legs + lastEnd→W`, is ≥ the plain sum of tour totals, and is flagged incomplete iff a leg failed.

### Implementation

- [ ] T014 [US1] Create `app/Services/WorkdayEstimator.php` (+ `WorkdayEstimate` value object / `CandidateTour` input struct): chain = prior tours (fixed saved start/end coords) then the candidate appended last; sum = `durationBetween(W→first.start)` + Σ between-legs + `durationBetween(last.end→W)` + Σ each segment's internal total; a **failed leg contributes 0 and sets `incomplete`** (FR-009/FR-015); a **null tour internal total** (unknown `travel_duration_s`, e.g. 2-point tour) contributes only that tour's **stop durations** and sets `incomplete` (**H1**); returns `{ projected_duration_s: int, incomplete: bool, start_index, start, end }`. Pure over injected `TravelTimeService`. (Start selection also lives here; its dedicated tests are US3.)
- [ ] T015 [P] [US1] Unit `tests/Unit/WorkdayEstimatorTest.php`: full-chain correctness; best-effort + `incomplete` when a leg fails; **H1** null tour-total → stop-time-only + incomplete; projected ≥ plain sum.
- [ ] T016 [US1] Update `app/Http/Requests/AvailableDriversRequest.php`: require `tour` (`integer`, `exists`) and authorize **ownership** — a foreign/unknown tour → `404` (mirror `AssignTourRequest`).
- [ ] T017 [US1] Update `app/Http/Controllers/DriverController.php`: load the owned candidate tour (+ ordered `stops`); fetch **all prior-tour totals for the date in one grouped aggregate** (no N+1 across drivers × tours — **M1**); build the distinct leg set and prime `TravelTimeService`; per available driver run `WorkdayEstimator`; emit `warehouse_name` + `projected_seconds` + `projected_incomplete` + `start_index` (the estimator's selected start position). Remove `assigned_seconds`.
- [ ] T018 [P] [US1] Update `resources/js/types/tour.ts`: `Driver` gains `warehouseName: string`, `projectedSeconds: number`, `projectedIncomplete: boolean`, `startIndex: number` (drop `assignedSeconds` + the `projectedSeconds()` helper).
- [ ] T019 [US1] Update `resources/js/hooks/use-tour-drivers.ts`: send `&tour=<id>`; map `warehouse_name`/`projected_seconds`/`projected_incomplete`/`start_index`.
- [ ] T020 [US1] Update `resources/js/components/tour/driver-list.tsx`: show the warehouse name in the driver info; render `projectedSeconds` via `formatDurationHm` with an **approximate/incomplete indicator** (icon + tooltip, role-named classes, "≥") when `projectedIncomplete` (FR-015); drop the `currentTourTotalS` prop.
- [ ] T021 [US1] Update `resources/js/components/tour/result-summary.tsx`: pass the tour id to `DriverList`; stop threading `currentTourTotalS`.
- [ ] T022 [P] [US1] Feature `tests/Feature/DriverAvailabilityTest.php` (`Http::fake`): `tour` required, ownership `404`, payload has `warehouse_name`/`projected_seconds`/`projected_incomplete`; a failed leg → best-effort figure + flag; no duplicate leg calls; `assigned_seconds` gone.
- [ ] T023 [P] [US1] Frontend `resources/js/components/tour/driver-list.test.tsx`: warehouse name shown, projected figure shown, incomplete indicator appears iff flagged.

**Checkpoint**: The chained projected workday is visible per driver, best-effort + flagged.

---

## Phase 4: User Story 2 — Start and end stops recorded on assignment (Priority: P1)

**Goal**: Assigning a tour records its start + end stop coordinates (loop = same stop; one-way = opposite endpoints) and the driver's day `sequence`, using the `start_index` the drivers payload already selected — so the assign UI is fully wired the moment this story lands.

**Independent Test**: Assign a looping tour → start = end coordinate; assign a one-way tour → start/end are opposite endpoints, an interior `start_index` is rejected; a second assignment for the same driver+date gets the next `sequence`; the presentation click-to-assign path sends the selected driver's `start_index` end-to-end.

### Implementation

- [ ] T024 [US2] Update `app/Http/Requests/AssignTourRequest.php`: require `start_index` (`integer`) and validate it is a **legal start position** for the bound tour — a `Tour::startCandidates()` position (looping → any stop position; one-way → first or last only); reject an interior position (`422`).
- [ ] T025 [US2] Update `app/Http/Controllers/TourAssignmentController.php`: resolve the start `Stop` at `start_index`; deduce the end via `Tour::endStopForStart`; compute `sequence = max(driver_tour.sequence for this driver + date) + 1`; write the pivot (`date`, `start_*`, `end_*`, `sequence`) via the idempotent `sync` on the unique `tour_id`; return `start_index` + `sequence` in the response.
- [ ] T026 [US2] Update `resources/js/hooks/use-assign-driver.ts`: accept + send `start_index` in the assign POST body.
- [ ] T027 [US2] Update `resources/js/components/tour/assign-driver-dialog.tsx`: accept a `startIndex` prop and pass it to `useAssignDriver`/the confirm call.
- [ ] T028 [US2] Update `resources/js/components/tour/driver-list.tsx`: pass the selected driver's `startIndex` (from the US1 payload field) into `AssignDriverDialog`, so the click-to-assign path carries it. (Depends on T020 + T027.)
- [ ] T029 [P] [US2] Feature `tests/Feature/TourAssignmentTest.php`: `start_index` legality (interior one-way → `422`); persisted start/end coords (loop = same stop, one-way = opposite endpoint); `sequence` increments per driver+date; still idempotent + ownership/eligibility enforced.
- [ ] T030 [P] [US2] Frontend `resources/js/components/tour/assign-driver-dialog.test.tsx`: `start_index` included in the assign request.

**Checkpoint**: Assignments durably record start/end stops + day order, and the assign UI sends the selected start end-to-end (no broken mid-stream state).

---

## Phase 5: User Story 3 — Start stop chosen as the closest valid stop (Priority: P1)

**Goal**: Prove the auto-selection is correct — each driver's candidate start is the valid stop with the shortest travel time from the incoming point (warehouse for the first tour, prior tour's end otherwise), it surfaces as `start_index`, and the assign call reuses it without recomputing. The selection algorithm is delivered in US1's `WorkdayEstimator` (T014); this story verifies and hardens it.

**Independent Test**: With two candidate starts at clearly different faked distances, the drivers payload `start_index` is the nearer one; the assign call persists exactly that index's start/end without re-selecting.

### Verification & hardening (tests)

- [ ] T031 [P] [US3] Unit `tests/Unit/WorkdayEstimatorTest.php` (selection cases): first tour → incoming = warehouse; later tour → incoming = prior tour's end; one-way near endpoint chosen as start → far endpoint becomes end; deterministic tie-break; all-unknown legs → lowest index + `incomplete`. (Extends the file created in T015.)
- [ ] T032 [P] [US3] Feature `tests/Feature/DriverAvailabilityTest.php` (`Http::fake` with distinct per-leg durations): `start_index` = the nearest valid candidate for the incoming point. (Extends T022.)
- [ ] T033 [US3] Feature `tests/Feature/TourAssignmentTest.php`: the assign endpoint **consumes** the provided `start_index` and does not re-run selection (the persisted start/end match the given index). (Extends T029.)

**Checkpoint**: Closest-start selection is correct and round-trips from list to assignment.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [ ] T034 Run `specs/013-inter-tour-travel/quickstart.md` checklist end-to-end (warehouse link, start/end, closest selection, chained figure, best-effort+flag, dedup+cap).
- [ ] T035 [P] Verify no regression in feature 002 geometry (existing `TourGeometry*` tests green after the T010 parser extraction) and in feature 012 assignment tests updated for the new payload/params.
- [ ] T036 [P] Confirm styling of the incomplete indicator uses only role-named palette classes (constitution VI) — no raw hex.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies.
- **Foundational (Phase 2)**: depends on Setup — **BLOCKS all stories**.
- **US1 (Phase 3)**: depends on Foundational (needs `WorkdayEstimator` deps, Tour accessors, TravelTimeService, warehouse link). Delivers the full drivers payload including `start_index`.
- **US2 (Phase 4)**: depends on Foundational + US1's payload `start_index` (T017/T018) for the click-to-assign wiring (T028). Self-contained: the assign UI works end-to-end once US2 lands.
- **US3 (Phase 5)**: tests-only; depends on US1 (estimator + payload) and US2 (assign round-trip).
- **Polish (Phase 6)**: after all stories.

### Cross-story file notes (sequential, same file)

- `driver-list.tsx` — US1 (T020, warehouse+projected) then US2 (T028, pass `startIndex`).
- `WorkdayEstimatorTest.php` — US1 (T015) then US3 (T031); `DriverAvailabilityTest.php` — US1 (T022) then US3 (T032); `TourAssignmentTest.php` — US2 (T029) then US3 (T033).

### Parallel Opportunities

- Foundational: T003, T004, T006, T007 are [P] (distinct files); T012, T013 [P] after their targets exist.
- US1: T015, T018, T022, T023 [P].
- US2: T029, T030 [P].
- US3: T031, T032 [P].

---

## Parallel Example: Foundational

```bash
# After the migration (T002):
Task: "Create Warehouse model in app/Models/Warehouse.php"          # T003
Task: "Create WarehouseFactory in database/factories/WarehouseFactory.php"  # T004
Task: "Set warehouse_id in DriverFactory"                            # T006
Task: "Assign warehouses in DriverDemoSeeder"                        # T007
```

---

## Implementation Strategy

### MVP (User Story 1)

1. Phase 1 Setup → Phase 2 Foundational (schema + services + Tour accessors).
2. Phase 3 US1 → the chained projected workday is visible per driver.
3. **STOP & VALIDATE**: projected = full chain, ≥ plain sum, flagged when a leg fails.

### Incremental

1. Foundational → US1 (visible chain, MVP) → US2 (assignment records start/end/sequence) →
   US3 (selection correctness + round-trip).
2. Each story is testable without breaking the previous.

---

## Notes

- [P] = different files, no incomplete-task dependency.
- Tests included per constitution I; write them to fail first where practical.
- Do **not** touch the async optimize/cache/broadcast path; `TourGeometryService::trace` stays pure.
- Commit after each task or logical group; keep the OpenStreetRouteClient extraction behavior-preserving.
