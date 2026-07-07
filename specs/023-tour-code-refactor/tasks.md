---

description: "Task list for Tour Code Refactor (023) — whole route-optimization back-end"
---

# Tasks: Tour Code Refactor (back-end)

**Input**: Design documents from `/specs/023-tour-code-refactor/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/frozen-io.md, observations.md

**Nature**: Back-end-only, **behavior-preserving** refactor of the whole route-optimization back-end. No new behavior tests. The existing PHP suite is the guardrail: after **every** implementation task, run `php artisan test` and confirm it is green with **no test logic changed** (a test may only be *retargeted* to a moved subject with identical assertions — prefer none). Endpoint I/O is frozen (`contracts/frozen-io.md`). Front-end refactor is **deferred** (out of scope). Issues noticed but not fixed → `observations.md`.

**Organization**: US1 = readability / SRP / correct class roles / de-duplication (the bulk). US2 = robustness preserved + dead code removed + observations kept current.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different file, no dependency on an incomplete task → parallelizable
- After each impl task: `php artisan test` green; `git diff` shows no test-logic change.
- **Never** touch front-end or vendored/starter-kit code.

## Path Conventions

Back-end, single repo: `app/Http/Controllers/`, `app/Http/Requests/`, `app/Services/`, `app/Jobs/`, new `app/Repositories/`, new `app/DTOs/`.

---

## Phase 1: Setup

- [X] T001 Establish the green baseline: run `composer test` (Pint check + `php artisan test`); record the passing test count as the invariant every later task must preserve.

---

## Phase 2: Foundational — new data layers (blocking; unused on creation → suite stays green)

- [X] T002 [P] Create `app/Repositories/TourRepository.php` owning all `Tour`/`Stop` Eloquent access, verb-named, with a wrapping `DB::transaction`: `createTourWithStops(...)`, `overwriteTourWithStops(...)` (loads the owned tour or throws `RuntimeException`, updates, replaces stops — **all in one transaction**, preserving the rollback proven by `TourRecorderEditTest::test_a_missing_edit_target_throws_and_creates_no_tour` — **[S2]**), `findOwnedTour(int $tourId, int $userId): ?Tour`. Wire no caller yet.
- [X] T003 [P] Create `app/Repositories/DriverTourRepository.php` owning the `driver_tour`/`stops` query access currently inline in controllers: `priorToursByDriver(string $date, array $driverIds): Collection` (the join + stops-grouping now in `DriverController`), `nextSequence(int $driverId, string $date): int` and `assign(int $tourId, int $driverId, array $pivot): void` (the sequence + `sync`/unique-violation logic now in `TourAssignmentController`). Wire no caller yet.

**Checkpoint**: repositories exist, suite green (nothing calls them).

---

## Phase 3: User Story 1 — Self-evident code: SRP, correct roles, no duplication (Priority: P1) 🎯 MVP

### Optimize + edit pipeline

- [X] T004 [US1] Refactor `app/Services/TourRecorder.php`: delegate persistence to `TourRepository` (inject); `record()` reads as `resolveDeliveryModeId → buildStopRows → (createTourWithStops | overwriteTourWithStops)`; extract `buildStopRows(...)` (ordered stop → `{latitude,longitude,duration_s,position}` via the existing duration-queue rule) and `resolveDeliveryModeId(...)`. Remove the dead `use App\Models\Stop;`. **[S3]** Do NOT rename/move/privatize the public static `coordinateKey()` — tests and `TourOptimizationService` depend on it. Depends on T002.
- [X] T005 [US1] Refactor `app/Http/Requests/OptimizeTourRequest.php`: replace the direct `Tour::find` in `authorize()` and `unassignedTourRule()` with `TourRepository::findOwnedTour(...)`. **[S5]** Preserve the `is_numeric($tourId)` guard and the exact outcomes — foreign/missing → 404, assigned/non-numeric-that-fails-integer → 422. Depends on T002.
- [X] T006 [US1] Refactor `app/Http/Controllers/TourPageController.php` to HTTP-only: add `app/DTOs/EditTourData.php` (immutable, `fromTour(Tour): self` factory shaping `{id, mode, loop, stops:[{lat,lng,duration_minutes}] in position order}`, mirroring `TourOptimizationResult`); look up via `TourRepository::findOwnedTour`; `edit()` keeps only 404 (foreign) / redirect (assigned) / `Inertia::render`. Depends on T002.
- [X] T007 [P] [US1] Decompose `app/Services/TourOptimizationService.php::optimize()` into `buildOptimizationInputs(...)` (coordinates, durations-by-coordinate, normalized coords, hash — **[N2]** not `prepareRequest`, which misreads as an HTTP request), `serveCachedTour(...)`, `dispatchOptimization(...)`; body reads `$inputs = …; return $this->serveCachedTour(...) ?? $this->dispatchOptimization(...)`. Keep the public static `persistError()` **[S3]**. Rename `durationByCoord` → `mapDurationsByCoordinate()` / `$durationsByCoordinate` **[N2]**.
- [X] T008 [P] [US1] Decompose `app/Jobs/OptimizeTourJob.php::handle()` into `optimizeUpstream(...)` (call client; on failure release lock + `markFailed` + broadcast `TourOptimizationFailed`, return null) and `persistAndBroadcast(...)` (cache, record, `markDone` + broadcast; on save failure `markFailed` + broadcast). **[S1]** `releaseActiveJob` MUST still run on **both** the failure branch and the success path — exactly once each.
- [X] T009 [P] [US1] Tidy `app/Http/Controllers/TourOptimizationController.php::optimizeTour()` to a pure translator: coerce `tour_id` to `?int` once, read defaults, call the service, map the `TourOptimizationResult` to done/failed/pending in one clear switch.

### Assignment

- [X] T010 [US1] Extract `app/Services/TourAssignmentService.php` from `TourAssignmentController.php`: move the start/end-stop selection, pivot building, `nextSequence`, and unique-violation handling into the service (using `DriverTourRepository` for the write + sequence); the controller validates via `AssignTourRequest`, calls the service, and returns the JSON. Behavior identical (`TourAssignmentTest` green). Depends on T003.

### Driver availability

- [X] T011 [US1] Extract `app/Services/DriverAvailabilityService.php` from `DriverController::available`: move the whole orchestration (build workdays, preload connections, assemble driver rows) into the service; move the `DB::table('driver_tour')`/stops queries (`priorToursByDriver`, `priorTourFromAssignment`) into `DriverTourRepository`. The controller validates via `AvailableDriversRequest`, calls the service, returns the JSON. Output array byte-identical (`DriverAvailabilityTest` green). Depends on T003.
- [X] T012 [US1] Decompose `DriverAvailabilityService` into short intent-named steps — e.g. `buildWorkdays`, `preloadTravelTimes`, `buildDriverRow`, `mandatoryBreakFor`, `incomingPoint`, `connectionsAlongChain` — so the top method reads as a sequence; keep the exact `projected_seconds` / `added_break` / `legs` computation and the walked-day break rule. Depends on T011.

### Geometry + supporting services

- [X] T013 [P] [US1] Ensure `app/Http/Controllers/TourGeometryController.php` is a pure translator (validate via `TourGeometryRequest`, call `TourGeometryService`, wrap the result); move any inline logic into the service.
- [~] T014 [P] [US1] AUDITED — representative long services (`TravelTimeService`, `TourGeometryService`) reviewed and found already well-decomposed (short verb-named methods, clear names); Pint ran clean across all. Deep decomposition of the remaining tested API clients / builders (`OpenStreetTspClient`, `OpenStreetRouteClient`, `WorkdayLegsBuilder`, `WorkdayEstimator`, `TourCache`, `PolylineDecoder`) was intentionally NOT forced — they already meet the style and re-cutting tested upstream clients is regression risk for marginal gain. Any residual candidate is optional; note in `observations.md` if pursued.
- [~] T015 [P] [US1] AUDITED — value objects / small services sampled and confirmed already clean (tiny, single-purpose, clear names); no structural change made. Pint clean.

### Cross-cutting

- [X] T016 [US1] Global naming pass across all touched back-end files: methods are verbs, variables are nouns, calls read business-first (`$this->tours->createTourWithStops(...)`, `$this->driverTours->priorToursByDriver(...)`). Fix flagged names — `durationByCoord`→`durationsByCoordinate`, `buildStopRows` (not `mapOrderedStops`), `buildOptimizationInputs` (not `prepareRequest`), avoid `…And…` two-job method names unless the two are inseparable. **No readability-hurting abbreviations, no fluff.** Depends on T004–T015.
- [X] T017 [US1] Global de-duplication pass: confirm tour/stop + driver_tour data access exists only in the two repositories; mutualise genuine remaining duplication only. **[N3]** Keep the persist-failure handling in `OptimizeTourJob` and `TourOptimizationService` **separate** — different log context (`job_uuid`) and different layers; merging would couple job ↔ service. Depends on T004–T016.

**Checkpoint**: roles correct, methods short, data access single-sourced — MVP complete, suite unchanged.

---

## Phase 4: User Story 2 — Robust and free of dead weight (Priority: P2)

- [X] T018 [US2] Dead-code sweep of all touched files (unused imports/symbols/unreachable branches). `composer lint:check` (Pint) clean; `php artisan test` green.
- [X] T019 [US2] Preserve-and-record pass: verify every failure path is byte-identical (persist_failed logging + `TourOptimizationFailed` broadcast on both paths; unique-violation swallow on assign; `TourOptimizationResult` accessor guards); confirm `observations.md` holds every noticed-but-deferred item (incl. O1/O2 and the deferred front-end refactor) and that **none** were acted on.

---

## Phase 5: Polish & Verification

- [X] T020 Final gate — all green, transparency proven:
  - `composer test` (Pint + full `php artisan test`) green; passing count equals the T001 baseline.
  - `git diff tests/` shows no changed test logic (only subject retargets, if any; ideally none).
  - `git diff --stat` shows only back-end files + the new `Repositories/` + `DTOs/`; no routes, migrations, or front-end changes.
  - `npm run test` still green (no JS/TS touched).

---

## Dependencies & Execution Order

- **T001** first.
- **T002** blocks **T004, T005, T006**. **T003** blocks **T010, T011**.
- **T007, T008, T009** — in-file decompositions, parallel any time after T001.
- **T012** after T011. **T013, T014, T015** parallel (different files).
- **T016** (naming) after all structural moves (T004–T015). **T017** (dedup) after T016.
- **US2 (T018–T019)** after US1. **T020** last.

### Parallel Opportunities

- After T001: T007, T008, T009 together (different files).
- After T002 & T003: T004/T005/T006 and T010/T011 across different files.
- T013, T014, T015 together.

---

## Implementation Strategy

### MVP First (User Story 1)

1. T001 baseline → T002/T003 repositories → the pipeline (T004–T009) → assignment (T010) → drivers (T011–T012) → geometry/services (T013–T015) → naming (T016) → dedup (T017). **Each task ends with a green suite → commit** for a bisectable trail.

### Safe & incremental

- One refactor per task, green suite after each → any regression is isolated to the last step.
- If a test's assertions would need changing to pass, **stop** — that's a behavior change; record it in `observations.md` and revert the step.

---

## Notes

- **Roles**: Controller (HTTP) → Service (logic, returns DTO) → Repository (data) / Client (API). After this, no Eloquent or `DB::table` in any controller or form request.
- **Frozen**: endpoint I/O per `contracts/frozen-io.md`; events, cache keys/TTLs, job args, logs, DB writes all unchanged.
- **Safety guards** carried inline: [S1] dual lock-release in the job, [S2] transaction + rollback in `overwriteTourWithStops`, [S3] don't touch public static `coordinateKey`/`persistError`, [S5] keep the request's `is_numeric` + 404/422 nuance.
- **No new tests, no weakened tests**; subject-retarget only if a moved responsibility forces it.
- **Out of scope**: front-end (deferred) and vendored/starter-kit code.
