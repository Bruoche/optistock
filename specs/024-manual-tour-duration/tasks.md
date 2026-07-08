---
description: "Task list for Manual Tour Duration Fallback"
---

# Tasks: Manual Tour Duration Fallback

**Input**: Design documents from `/specs/024-manual-tour-duration/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/force-tour.md, contracts/frozen-io.md, quickstart.md

**Tests**: Included — the project's gate and constitution (I. Quality First) require tests for new behavior; the plan's testing strategy lists them explicitly.

**Organization**: Grouped by user story (US1 = P1 force fallback, US2 = P2 transparency, US3 = P3 best-effort workday + driver-path robustness).

## Path Conventions

Web app: Laravel backend at repo root (`app/`, `routes/`, `config/`, `tests/`), Inertia/React frontend at `resources/js/`.

---

## Phase 1: Setup (Shared scaffolding)

**Purpose**: Small shared additions several stories build on.

- [ ] T001 [P] Add `openstreet.route_connect_timeout` (env `OPENSTREET_ROUTE_CONNECT_TIMEOUT`, default 10) in `config/services.php`, with a comment mirroring the TSP client's connect-timeout rationale (fail fast on a dead host)
- [ ] T002 [P] In `resources/js/types/tour.ts`: add `forced?: boolean` to the `OptimizeState` `'done'` variant and export `MAX_TOUR_DURATION_MINUTES = 1440`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Confirm the reused base — no new foundational code.

The persistence layer from feature 023 is reused **unchanged**: `TourRecorder::record` (create OR overwrite-in-place by `editTourId`), `TourRepository::createTourWithStops`/`overwriteTourWithStops`, the `TourOptimizationResult` DTO, and `CoordinateNormalizer`. No migration. No new foundational task.

**Checkpoint**: Reused persistence + DTO confirmed available → user stories can begin.

---

## Phase 3: User Story 1 - Force a tour when optimization is unavailable (Priority: P1) 🎯 MVP

**Goal**: A synchronous `POST /api/tour/force` writes a tour in current stop order with a manual drive duration; the frontend reveals the field + **Force Tour** button on optimization failure and settles the result exactly like an optimized tour.

**Independent Test**: With the routing API down, place ≥2 stops → Optimize fails → field + button appear → enter a duration → Force Tour → tour saved in entered order with that drive duration, result view shown, offered for assignment.

### Tests for User Story 1 ⚠️ (write first, ensure they fail)

- [ ] T003 [P] [US1] Feature test — force **create**: `POST /api/tour/force` persists stops in input order, `travel_duration_s` = payload seconds, `total_distance_m` null, response mirrors the optimize `done` shape (`{status:'done', data:{id, ordered_stops, total_distance_m:null, total_duration_s}}`) in `tests/Feature/Tour/ForceTourTest.php`. **Assert `data.total_duration_s` equals the manual drive seconds (driving-only, = optimize payload semantics), NOT drive+stops** — guards against returning `Tour::total_duration_s` accessor by mistake
- [ ] T004 [P] [US1] Feature test — force **edit-in-place**: with an owned unassigned `tour_id`, the tour is overwritten (stops replaced in input order) not duplicated; a vanished/foreign target → `persist_failed` / `404` per contract, in `tests/Feature/Tour/ForceTourEditTest.php`
- [ ] T005 [P] [US1] Feature test — **validation matrix**: missing/zero/negative/non-integer/`>86400` `travel_duration_s` → 422; `<2`/`>10` stops → 422; out-of-range coord → 422; foreign `tour_id` → 404; assigned `tour_id` → 422, in `tests/Feature/Tour/ForceTourValidationTest.php`
- [ ] T006 [P] [US1] Frontend test — `tour-control-bar`: the drive-duration field + Force Tour button render only when `state.status === 'failed'`, and Force Tour is disabled until a valid (≥1 min) duration with ≥2 stops, in `resources/js/components/tour/tour-control-bar.test.tsx`
- [ ] T007 [P] [US1] Frontend test — `use-tour-optimization`: `forceTour` POSTs `/api/tour/force` with `travel_duration_s = minutes*60` (+`tour_id` when editing) and settles `done` with `forced:true`; failure keeps `failed` state, in `resources/js/hooks/use-tour-optimization.test.ts`

### Implementation for User Story 1

- [ ] T008 [P] [US1] Create `ForceTourRequest extends OptimizeTourRequest` in `app/Http/Requests/ForceTourRequest.php` — reuse inherited rules, override `rules()` to merge `travel_duration_s => ['required','integer','min:1','max:86400']`, add a clear message
- [ ] T009 [P] [US1] Create `TourForcingService::force(userId, stops, mode, loop, travelDurationS, editTourId): TourOptimizationResult` in `app/Services/TourForcingService.php` — normalize coords (`CoordinateNormalizer`), build input-order `orderedStops` (`order = index`) + `durationByCoord`, call `TourRecorder::record(..., distanceM:null, durationS:travelDurationS, editTourId)`, wrap `ready`; on persist `Throwable` log with context + return `failed(persistError())` (mirror `TourOptimizationService::recordCacheHit`)
- [ ] T010 [US1] Create `TourForceController::force(ForceTourRequest, TourForcingService): JsonResponse` in `app/Http/Controllers/TourForceController.php` — resolve mode/loop/tour_id like `TourOptimizationController::optimizeTour`, call the service, map `ready → 200 done` / `failed → 200 failed`. **The `done` `data.total_duration_s` MUST be the driving-only manual seconds (`travel_duration_s`), matching the optimize payload — NOT `Tour::total_duration_s` (which is drive+stops)** (depends on T008, T009)
- [ ] T011 [US1] Register `POST tour/force` (`middleware('throttle:tour-read')`, `name('tour.force')`) in the `auth` group of `routes/api.php` (depends on T010)
- [ ] T012 [US1] Add `forceTour(mode, loop, durationMinutes)` to `resources/js/hooks/use-tour-optimization.ts` — POST `/api/tour/force`, thread `editTourId.current`, on `200 done` settle `done` with `forced:true`, reuse `settleFailed` for `failed`/422/429/network; return it from the hook (depends on T002)
- [ ] T013 [US1] Add the conditional drive-duration field (minutes, whole-number clamp to `MAX_TOUR_DURATION_MINUTES`, palette-styled reusing the existing input pattern) + **Force Tour** `ActionButton` to `resources/js/components/tour/tour-control-bar.tsx`, rendered only when a new `failed`/`onForceTour` prop set is present; disabled until valid (depends on T002)
- [ ] T014 [US1] Wire `resources/js/pages/tour/optimize.tsx` — hold the manual-duration state, pass failed-state + `onForceTour` into `TourControlBar`, and thread the `forced` flag from the `done` state onward (depends on T012, T013)

**Checkpoint**: Force path works end-to-end with the API down — MVP.

---

## Phase 4: User Story 2 - Transparent warnings when data can't be obtained (Priority: P2)

**Goal**: A forced tour is visibly marked "Manually entered"; unknown distance/route stay shown as unknown (never zero); every handled failure is logged.

**Independent Test**: Force a tour → its drive duration shows a "Manually entered" badge, distance shows unknown, and a forced persist failure produces a log entry with context.

### Tests for User Story 2 ⚠️

- [ ] T015 [P] [US2] Frontend test — `result-summary`: shows a "Manually entered" badge on the drive duration when `forced` is true, and hides it otherwise; distance still renders as unknown when `total_distance_m` is null, in `resources/js/components/tour/result-summary.test.tsx`
- [ ] T016 [US2] Feature test — a forced persist failure (vanished edit target) writes a `Log` entry with `user_id` + context and returns `persist_failed` (assert via `Log::spy`), in `tests/Feature/Tour/ForceTourTest.php` (same file as T003 — no `[P]`)

### Implementation for User Story 2

- [ ] T017 [US2] Add the "Manually entered" duration badge to `resources/js/components/tour/result-summary.tsx` — new `forced?: boolean` prop, role-named palette colors only, reusing existing badge/label styling (no raw hex)
- [ ] T018 [US2] Pass the `forced` flag from the `done` state through `resources/js/pages/tour/optimize.tsx` into `ResultSummary` (depends on T014, T017)

**Checkpoint**: Forced tours are honestly labelled; degraded values stay flagged.

---

## Phase 5: User Story 3 - Best-effort driver workday + non-blocking driver back-end (Priority: P3)

**Goal**: A forced tour's saved duration drives the workday; the entire driver-assignment back-end never blocks on an API outage (fail-fast connect timeout on the `/route` client).

**Independent Test**: With a forced tour and `/route` faked down, `GET /api/tour/drivers` returns rows built from the saved duration + known travel times, flagged `projected_incomplete`, with no hang or 500; assignment then succeeds.

### Tests for User Story 3 ⚠️

- [ ] T019 [P] [US3] Feature test — `GET /api/tour/drivers` with the `/route` host faked as a connection error returns `200`, rows present, `projected_incomplete:true`, no exception, in `tests/Feature/Tour/DriverAvailabilityRobustnessTest.php`
- [ ] T020 [P] [US3] Unit test — `OpenStreetRouteClient` applies the configured connect timeout on `traceLeg` and exposes `connectTimeout()`, in `tests/Unit/Services/OpenStreetRouteClientTest.php`
- [ ] T021 [P] [US3] Feature test — a forced tour is assignable and its `travel_duration_s` feeds the driver workday total (candidate `Tour::total_duration_s` = manual drive + stop seconds), in `tests/Feature/Tour/ForceTourWorkdayTest.php`

### Implementation for User Story 3

- [ ] T022 [US3] `app/Services/OpenStreetRouteClient.php` — accept a `connectTimeout` ctor arg **with a default (e.g. `connectTimeout = 10`) so existing direct instantiations (tests) keep working**, apply `->connectTimeout($this->connectTimeout)` on `traceLeg` (additive only — read `timeout`/params unchanged so the frozen geometry endpoint's happy path is untouched), and add a `connectTimeout(): int` accessor (mirrors `timeout()`) (depends on T001)
- [ ] T023 [US3] `app/Services/TravelTimeService.php` — apply `->connectTimeout($this->client->connectTimeout())` on the pooled `/route` requests in `fetchBatch` (depends on T022)
- [ ] T024 [US3] `app/Providers/AppServiceProvider.php` — pass `connectTimeout: (int) $config['route_connect_timeout']` into the `OpenStreetRouteClient` singleton (depends on T001, T022)

**Checkpoint**: Driver path is bounded + best-effort under an outage; forced tours assign normally.

---

## Phase 6: Polish & Cross-Cutting

- [ ] T025 [P] Run the `quickstart.md` API-down walkthrough manually (optimize fail → force → assign) and confirm the badge + unknown-distance rendering; **explicitly verify FR-012 for a forced tour: with `/route` down the map still renders (straight segments, no blank), route metrics show "unavailable"** (reuses existing 002/013 geometry fallback — no new code, just confirm it holds for the forced path)
- [ ] T026 Run the full gate green: `php artisan test`, `npm test`, `npm run lint`, `npm run types` (tsc), `npm run format:check` (prettier — separate from lint, do not skip)
- [ ] T027 [P] Sweep any behavior/robustness note surfaced during work into the spec's assumptions or a follow-up, and confirm no frozen contract (`contracts/frozen-io.md`) drifted

---

## Dependencies & Execution Order

### Phase order
- **Setup (P1)** → **Foundational (P2, no code)** → **US1 (P3)** → **US2 (P4)** → **US3 (P5)** → **Polish (P6)**.
- US2 depends on US1 (needs the `forced` flag + result view). US3's robustness half is independent of US1/US2; its forced-workday test (T021) depends on US1's endpoint.

### Within US1
- Tests T003–T007 first (fail).
- Backend: T008 + T009 [P] → T010 → T011.
- Frontend: T012 + T013 [P] (both need T002) → T014.

### Within US3
- T022 → T023 (needs the accessor); T024 needs T001 + T022. T019/T020/T021 authored first.

### Parallel opportunities
- Setup: T001 ‖ T002.
- US1 tests T003–T007 all ‖. Impl: T008 ‖ T009; T012 ‖ T013.
- US3 tests T019 ‖ T020 ‖ T021.

---

## Parallel Example: User Story 1

```bash
# Tests together:
Task: "Feature test force create in tests/Feature/Tour/ForceTourTest.php"
Task: "Feature test force edit-in-place in tests/Feature/Tour/ForceTourEditTest.php"
Task: "Feature test validation matrix in tests/Feature/Tour/ForceTourValidationTest.php"
Task: "Frontend test tour-control-bar in resources/js/components/tour/tour-control-bar.test.tsx"
Task: "Frontend test forceTour in resources/js/hooks/use-tour-optimization.test.ts"

# Backend implementation together:
Task: "ForceTourRequest in app/Http/Requests/ForceTourRequest.php"
Task: "TourForcingService in app/Services/TourForcingService.php"
```

---

## Implementation Strategy

### MVP (User Story 1)
1. Setup (T001–T002) → Foundational (confirm reuse).
2. US1 (T003–T014) → **STOP and VALIDATE**: force a tour with the API down, reach assignment.
3. Ship.

### Incremental
- Add US2 (transparency badge + logging) → validate labelling.
- Add US3 (robustness connect timeout + workday) → validate no-block under outage.
- Polish + full gate.

---

## Notes
- [P] = different files, no incomplete-task dependency.
- Reuse over new code: `TourRecorder`/`TourRepository`/`TourOptimizationResult`/`CoordinateNormalizer` unchanged; `ForceTourRequest extends OptimizeTourRequest`; response mirrors optimize `done`.
- Frozen: optimize / geometry / drivers-output / assign contracts (`contracts/frozen-io.md`).
- Commit after each task or logical group.
