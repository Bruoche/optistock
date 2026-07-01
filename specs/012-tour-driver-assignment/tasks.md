---
description: "Task list for Tour Driver Assignment (+ tour/stop persistence)"
---

# Tasks: Tour Driver Assignment (+ tour/stop persistence)

**Input**: Design documents from `specs/012-tour-driver-assignment/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md,
contracts/tour-persistence.md, contracts/tour-assignment.md

**Tests**: Included — constitution mandates tests; plan Constitution Check lists them.

**Organization**: The tour/stop persistence refactor is **foundational** (both user
stories need persisted tours/assignments), so it lives in Phase 2. Stories then layer on:

- **US1 (P1)** — Assign the tour to a driver (click → confirm → persist → return). 🎯 MVP
- **US2 (P1)** — Show each driver's projected working hours for the date.
- **US3 (P2)** — Cancel the confirmation without assigning.

## Path Conventions

Web app (Laravel + React SPA): backend under `app/`, `database/`, `routes/`, `tests/`;
frontend under `resources/js/`.

---

## Phase 1: Setup

- [ ] T001 Confirm the shared `Dialog` primitive exists at `resources/js/components/ui/dialog.tsx` (used for the assignment confirmation; no `alert-dialog` needed). No new dependencies.

---

## Phase 2: Foundational — Tour/Stop persistence refactor (Blocking Prerequisites)

**Purpose**: Persist tours + stops on optimize and thread the tour id + road duration through, without disturbing the async optimize/cache/broadcast flow. Blocks US1 and US2.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

### Schema, models, factories

- [ ] T002 Create migration `database/migrations/2026_07_01_000002_create_tour_tables.php` — `tours` (`id`, `user_id` FK cascade, `delivery_mode_id` FK, `loop` bool, `travel_duration_s` unsigned int nullable, `total_distance_m` unsigned int nullable, timestamps), `stops` (`id`, `tour_id` FK cascade, `latitude`/`longitude` decimal(10,7), `duration_s` unsigned int, `position` unsigned int, timestamps, unique `(tour_id, position)`), `driver_tour` (`id`, `tour_id` FK cascade **unique**, `driver_id` FK cascade, `date` date, timestamps, index `(driver_id, date)`); `down()` drops in reverse.
- [ ] T003 [P] Create `app/Models/Stop.php` — `belongsTo(Tour)`; fillable `latitude, longitude, duration_s, position`.
- [ ] T004 [P] Create `app/Models/Tour.php` — `belongsTo(DeliveryMode)` (as `deliveryMode`), `belongsTo(User)`, `hasMany(Stop)` ordered by `position`, `belongsToMany(Driver, 'driver_tour')->withPivot('date')` (as `drivers`), and a `total_duration_s` accessor that **propagates unknown**: `travel_duration_s === null ? null : travel_duration_s + stops->sum('duration_s')` — do NOT coalesce null to 0 (FR-012; null travel = unknown, kept distinct from zero so the frontend can detect it). `travel_duration_s` stays a plain writable nullable column (no source column) so a future manual-entry setter can populate it (FR-013).
- [ ] T005 Add the inverse relation to `app/Models/Driver.php` — `tours(): belongsToMany(Tour::class, 'driver_tour')->withPivot('date')` (alongside `deliveryModes`/`weekDays`).
- [ ] T006 [P] Create `database/factories/StopFactory.php` and `database/factories/TourFactory.php` — a tour with a mode, `loop`, optional `travel_duration_s`, and a small ordered set of stops; a helper to attach a driver with a `date` pivot for tests.

### Persistence service + optimize wiring

- [ ] T007 In `app/Services/TourOptimizationService.php`, build a **normalized-coordinate → `duration_s` map** from the request stops (round each coord to `CoordinateNormalizer::PRECISION` = 5 dp, matching the cache key) and thread it to `TourRecorder`. **Do NOT rely on a TSP source index**: `OpenStreetTspClient::mapToTour` drops the input index, `normalize()` rounds + re-sorts before dispatch, and the cache-hit path makes no TSP call — the normalized coord is the only join key that works on both done-paths (and for stale, old-shape cached tours). Handle duplicate coords by consuming the map per-coord in order (identical-coord stops are interchangeable for duration). Document the mapping (research R4).
- [ ] T008 Create `app/Services/TourRecorder.php` — `record(int $userId, string $mode, bool $loop, array $orderedStops, array $durationByCoord, ?int $distanceM, ?int $durationS): Tour` creating the `Tour` + `Stop` rows in a **DB transaction**, `position` = optimized order, each stop's `duration_s` resolved by looking up its normalized coord in `$durationByCoord` (T007). Resolves `delivery_mode_id` from the mode label. **Integrity (RB3/FR-014)**: if an ordered stop's coordinate has **no** entry in `$durationByCoord` (a real invariant break, not a duplicate-coord collision), throw so the transaction rolls back — do NOT silently write `duration_s = 0`. Let the persistence exception propagate to the caller (T010/T011) which logs + surfaces it (D10).
- [ ] T009 Update `app/Http/Requests/OptimizeTourRequest.php` — accept `stops` (2–10; each `lat` between -90,90, `lng` between -180,180, `duration_s` required unsigned int) replacing bare `coordinates`; keep the messages. **Update existing optimize tests** (`tests/Feature/…` referencing the old `coordinates` payload) to the new `stops` shape — this rename breaks them and they must land together (research regression checklist).
- [ ] T010 Update `app/Services/TourOptimizationService.php` — accept the stops-with-durations; on a **cache hit**, call `TourRecorder->record(...)` and return the persisted tour (id included); on a miss, pass durations through to the job. Keep normalize/hash/claim/dedup intact. **Persist-failure handling (D10/FR-014)**: wrap the cache-hit `record(...)` in try/catch; on failure **log (`error`) with user + coordinates-hash context** and return a **failed** `TourOptimizationResult` (new `failed(TourError)` state, code `persist_failed`) — never let it become a raw 500 that silently regresses the read-fast path.
- [ ] T011 Update `app/Jobs/OptimizeTourJob.php` — carry the stop durations; on TSP **success**, `putTour` (cache) **first**, then call `TourRecorder->record(...)` wrapped in its own try/catch. On persist failure: **log (`error`) with job/user context**, `markFailed` + dispatch `TourOptimizationFailed` with code `persist_failed` (do NOT let it escape into `failed()` as a generic crash, and do NOT flip a cached-successful optimization into a confusing generic error). On success include the persisted `tour_id` in the `markDone` payload and the `TourOptimized` dispatch. The active-job lock is released on both outcomes. Existing TSP failure paths (`handle` catch + `failed()`) unchanged. (D10)
- [ ] T012 Update `app/Events/TourOptimized.php` — include `tour_id` (the persisted id) in the broadcast `data`.
- [ ] T013 Update `app/Services/TourOptimizationResult.php` + `app/Http/Controllers/TourOptimizationController.php` — carry/return the persisted `tour_id` (`data.id`) on the `200 done` path. Add a **`failed(TourError)` state** to `TourOptimizationResult` (alongside `ready`/`pending`); the controller maps it to `200 { status: 'failed', error: { code: 'persist_failed', message } }` — the same shape the poll/broadcast settle already understands — so a cache-hit persist failure (T010) is surfaced to the client, not a raw 500 (D10/FR-014).

### Geometry persists the road duration

- [ ] T014 Update `app/Http/Requests/TourGeometryRequest.php` — accept optional `tour_id` (integer, exists in `tours`).
- [ ] T015 Update `app/Http/Controllers/TourGeometryController.php` — when `tour_id` is present, resolves to a tour **owned by the user**, and `trace()` returned non-null totals, persist the traced `travel_duration_s` + `total_distance_m` onto that tour. Keep `TourGeometryService::trace` a **pure function** (no persistence side-effect); the persist happens in the controller after it returns. The map trace response is unchanged and a missing/foreign/null-total id never fails the trace. **Logging (RB4/constitution IV)**: when a **provided** `tour_id` is ignored (not found / not owned / null totals), log it (`info`/`warning` with the id + user) rather than skipping silently; a failure of the persist write itself is logged (`warning`) and swallowed — the tour is already persisted with its seed and stays assignable, so this refinement failure is not surfaced to the user (contrast D10's optimize-persist failure, which is).

### Frontend threading

- [ ] T016 Update `resources/js/types/tour.ts` — add `id: number` to `TourResult`; add `'persist_failed'` to the `TourError['code']` union (the surfaced save-failure code, D10/FR-014).
- [ ] T017 Update `resources/js/hooks/use-tour-optimization.ts` — send `stops: [{lat,lng,duration_s}]` (durations from stop minutes × 60) instead of bare coordinates; carry `result.id` into the `done` state (200, broadcast, and poll payloads). **Handle persist failure**: on the `200` response branch on `payload.status` — `'done'` → settle done; `'failed'` → `settleFailed(payload.error)` (toasts the `persist_failed` message). The broadcast/poll `.TourOptimizationFailed` path already routes `persist_failed` through `settleFailed`, so a save error on the job path toasts too. A route that failed to persist never enters the `done` state, so it is never offered for assignment (FR-014).
- [ ] T018 Update `resources/js/hooks/use-tour-geometry.ts` — include `tour_id` (from the done result) in the `POST /api/tour/geometry` body; keep the identity token guard.

### Foundational tests

- [ ] T019 [P] `tests/Feature/TourPersistenceTest.php` — optimizing persists exactly one `tours` row + its `stops` (correct `position`, per-stop `duration_s` mapped through the normalize reorder — assert a case where the TSP order ≠ input order so the coord→duration map is exercised) on the **cache-hit** path; the same on the **job** path; the broadcast+poll dual-settle does not duplicate; a failed optimization persists nothing; `data.id` returned. **Persist-failure (D10/FR-014)**: when `TourRecorder::record` throws, the **cache-hit** path returns `200 { status:'failed', code:'persist_failed' }` (no partial rows, error logged), and the **job** path marks failed + broadcasts `persist_failed` (not a generic crash) while the TSP result stays cached so a retry re-attempts only the save.
- [ ] T020 [P] `tests/Unit/TourTest.php` — `total_duration_s` accessor = `travel_duration_s + Σ stop.duration_s` when travel is known; **returns null (not the stops-only sum) when `travel_duration_s` is null** (unknown-state propagation, FR-012); `stops` ordered by `position`.
- [ ] T021 [P] `tests/Feature/TourGeometryPersistTest.php` — posting geometry with a `tour_id` (incl. a **2-point tour**, which IS traced) updates that tour's `travel_duration_s` + `total_distance_m` to the road totals; a **foreign/non-owned** `tour_id` persists nothing (and still returns the trace); a trace with a failed leg (null totals) leaves the seed untouched.

**Checkpoint**: Optimizing persists a tour+stops; the tour id + road duration reach the frontend. Foundation ready.

---

## Phase 3: User Story 1 - Assign the tour to a driver (Priority: P1) 🎯 MVP

**Goal**: Clicking a driver opens a confirmation; confirming records a `driver_tour` assignment and returns the manager to the cleared creation menu.

**Independent Test**: Optimize a tour, click a driver, confirm, verify a `driver_tour` row exists and the view is the empty creation menu.

### Tests for US1 ⚠️ (write first)

- [ ] T022 [P] [US1] `tests/Feature/TourAssignmentTest.php` — `POST /api/tour/{tour}/assign` creates a `driver_tour` (driver+date); an **ineligible** driver (wrong mode or not scheduled that weekday) → 422 and no row; unknown tour → 404; **another user's tour → 404 and no row** (ownership); unauth → 401; a second assign `updateOrCreate`s the same tour (idempotent).
- [ ] T023 [P] [US1] `resources/js/components/tour/assign-driver-dialog.test.tsx` — confirming calls the assign hook with the tour id + driver + date and, on success, invokes `onAssigned`; the dialog names the driver.

### Implementation for US1

- [ ] T024 [US1] Create `app/Http/Requests/AssignTourRequest.php` — `driver_id` required + exists + **eligible** for the bound tour (supports its `delivery_mode` AND scheduled on `date`'s weekday, via the 006/011 rules); `date` required|date. **Authorize on tour ownership**: `authorize()` returns false (→ 404 via the controller/route binding) unless the bound tour's `user_id` === the requesting user.
- [ ] T025 [US1] Create `app/Http/Controllers/TourAssignmentController.php` — `assign(AssignTourRequest, Tour $tour)` → after the request's ownership + eligibility checks pass, `updateOrCreate` the `driver_tour` row keyed by `tour_id`; return `{ data: { tour_id, driver_id, date } }`. A non-owned tour must surface as 404 (not 403) so a foreign tour id is not confirmed to exist. **Concurrency (RB5)**: a concurrent double-assign can race `updateOrCreate` into a unique-`tour_id` violation — catch the `QueryException`/unique violation and treat it as an idempotent success (re-read the row) rather than a 500.
- [ ] T026 [US1] Add `POST tour/{tour}/assign` → `TourAssignmentController@assign` to `routes/api.php` in the `auth` group with `throttle:tour-read`; name `tour.assign`.
- [ ] T027 [P] [US1] Create `resources/js/hooks/use-assign-driver.ts` — `POST /api/tour/{tourId}/assign { driver_id, date }`; expose an async `assign(driverId)` returning success/failure (toast on failure).
- [ ] T028 [US1] Create `resources/js/components/tour/assign-driver-dialog.tsx` — confirmation over the shared `Dialog`, naming the driver + delivery; Confirm → assign → `onAssigned`; Cancel → close. One open at a time.
- [ ] T029 [US1] Update `resources/js/components/tour/driver-list.tsx` — make each row a focusable button that opens the dialog for that driver; accept `tourId` + `onAssigned` props.
- [ ] T030 [US1] Thread props: `resources/js/components/tour/result-summary.tsx` passes `tourId={result.id}` + `onAssigned` into `DriverList`; `resources/js/pages/tour/optimize.tsx` passes `onAssigned={reset}` (returns to the cleared creation menu).

**Checkpoint**: Click → confirm → assignment persisted → back on the empty creation menu. US1 independently testable.

---

## Phase 4: User Story 2 - Projected working hours (Priority: P1)

**Goal**: Each driver row shows their projected hours for the date — committed assigned time plus this tour.

**Independent Test**: Seed a driver with an assigned tour for the date; open the list for a tour of duration D and confirm the row shows committed + D, formatted.

### Tests for US2 ⚠️

- [ ] T031 [P] [US2] Extend `tests/Feature/DriverAvailabilityTest.php` — each returned driver includes `assigned_seconds` = Σ (`COALESCE(travel_duration_s,0)` + `Σ stop.duration_s`) of their tours assigned for the queried `date` (0 when none; only that date's assignments counted); a committed tour with **null** `travel_duration_s` still contributes its stop time (travel counted as 0, sum stays numeric).
- [ ] T032 [P] [US2] Extend `resources/js/components/tour/driver-list.test.tsx` — a row shows projected hours = `assignedSeconds + currentTourTotalS`, formatted; a driver with no assignments shows just the current tour total.

### Implementation for US2

- [ ] T033 [US2] Add the committed-load computation to `app/Models/Driver.php` (a scope/withSum or query helper) and include `assigned_seconds` for the queried date in the `app/Http/Controllers/DriverController.php` payload (eager/aggregate — no N+1). The aggregate sums `COALESCE(travel_duration_s, 0) + Σ stop.duration_s` per assigned tour — a committed tour with **unknown** (null) travel still contributes its stop time (0 travel), so the sum never turns null. This COALESCE is deliberately separate from the Tour `total_duration_s` accessor, which propagates null for per-tour detection (T004/FR-012).
- [ ] T034 [US2] Update `resources/js/hooks/use-tour-drivers.ts` — map `assigned_seconds` → `Driver.assignedSeconds`; add the field to `resources/js/types/tour.ts` and a `formatProjectedHours`/reuse of the duration formatter.
- [ ] T035 [US2] Update `resources/js/components/tour/driver-list.tsx` — render each row's projected hours = `assignedSeconds + currentTourTotalS`; accept `currentTourTotalS`.
- [ ] T036 [US2] Pass `currentTourTotalS` (road duration + wait, already computed) from `resources/js/components/tour/result-summary.tsx` into `DriverList`.

**Checkpoint**: Rows show correct projected hours that update with the date. US1 + US2 both work.

---

## Phase 5: User Story 3 - Cancel the confirmation (Priority: P2)

**Goal**: Cancelling the confirmation makes no assignment and leaves the presentation intact.

**Independent Test**: Open the dialog, cancel, confirm no `driver_tour` row and the list unchanged.

### Tests for US3 ⚠️

- [ ] T037 [P] [US3] Extend `resources/js/components/tour/assign-driver-dialog.test.tsx` — cancelling/dismissing does not call the assign hook and closes the dialog; the list/presentation stays.

### Implementation for US3

- [ ] T038 [US3] Ensure the Cancel/dismiss path in `resources/js/components/tour/assign-driver-dialog.tsx` closes without calling assign and without `onAssigned`; a failed assign (from the hook) also keeps the dialog context and toasts (FR-011) rather than navigating.

**Checkpoint**: All three stories functional.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [ ] T039 [P] Update `specs/006-driver-assignment/contracts/driver-availability.md` and `011` contract with a pointer to the new `assigned_seconds` field / the assign endpoint (discoverability).
- [ ] T040 Run `php artisan test` and `npm run test` — all suites green.
- [ ] T041 Run `./vendor/bin/pint`, `npm run lint`, `npm run types:check`, `npm run format:check` and fix issues in changed files.
- [ ] T042 Execute `specs/012-tour-driver-assignment/quickstart.md` end-to-end (persist on optimize; assign + return; projected hours across two tours same date; cancel).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: after Setup — BLOCKS US1 and US2 (persisted tours/stops, tour id + road duration threading, Driver↔Tour relation).
- **US1 (Phase 3)**: after Foundational. MVP.
- **US2 (Phase 4)**: after Foundational; independent of US1's UI (can seed assignments directly). May proceed in parallel with US1 after Phase 2.
- **US3 (Phase 5)**: after US1 (extends the dialog).
- **Polish (Phase 6)**: after the desired stories.

### Within Foundational

- T002 → T003/T004/T005/T006 (models/factories need the tables).
- T007 → T008 (mapping before the recorder); T008 → T010/T011 (recorder before its callers).
- T009 (request) → T017 (client sends the new shape) — change together.
- T013/T012/T011 (tour_id in payloads) → T016/T017 (client reads id) → T018 (geometry sends id) → T015 (server persists via id).

### Parallel Opportunities

- Foundational: T003, T004, T006 `[P]`; the three foundational tests T019–T021 `[P]`.
- US1 tests T022/T023 `[P]`; hook T027 `[P]` with the request/controller.
- US2 tests T031/T032 `[P]`.
- After Phase 2, US1 and US2 can be built in parallel by different people.

---

## Parallel Example: Foundational tests

```bash
Task: "TourPersistenceTest (cache-hit + job persist, no dup) in tests/Feature/TourPersistenceTest.php"
Task: "TourTest total_duration_s accessor in tests/Unit/TourTest.php"
Task: "TourGeometryPersistTest in tests/Feature/TourGeometryPersistTest.php"
```

---

## Implementation Strategy

### MVP First

1. Phase 1 → Phase 2 Foundational (the persistence refactor — the bulk + the risk).
2. Phase 3 US1 (assign) → **STOP and VALIDATE**: click → confirm → persisted → cleared menu.

### Incremental Delivery

1. Foundational ready → US1 (assign, MVP) → US2 (projected hours) → US3 (cancel).
2. Commit after each checkpoint; keep the optimize/cache/broadcast flow green throughout.

---

## Notes

- **Regression watch** (research "Regression checklist"): persist on both done-paths with no duplication; the existing TSP **failure** paths untouched; 2-point tours **are traced** (travel becomes the road value, or null when undetermined — never the estimate silently); `coordinates`→`stops` request change lands on client + server (and existing tests) together.
- Persistence writes are transactional; a **persist failure is logged and surfaced to the user** (toast, `persist_failed`) on both done-paths — never a silent unsaved route, and an unsaved route is never offered for assignment (D10/FR-014). Assignment re-validates ownership + eligibility server-side and surfaces failures without navigating (FR-011); the unique-`tour_id` race is caught as idempotent success (RB5).
- Geometry-persist is a refinement of an already-saved tour: its failure/skip is logged, not surfaced (RB4).
- Never-assigned tours accumulate (accepted; cleanup deferred — follow-up ticket for a `created_at` prune).
- `[P]` = different files, no incomplete-task dependency. Commit after each task or logical group.
