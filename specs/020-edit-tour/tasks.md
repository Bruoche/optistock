---

description: "Task list for Edit Tour (020)"
---

# Tasks: Edit Tour

**Input**: Design documents from `/specs/020-edit-tour/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/edit-tour.md

**Tests**: Included — the constitution requires tests for behavior affecting correctness (Quality First; new-behavior-needs-tests).

**Organization**: Grouped by user story. US1 (edit-in-place) is the MVP; US2 (button relabel) is an independent cosmetic increment.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 or US2

## Path Conventions

Web app, single repo: PHP under `app/`, `routes/`, `tests/`; frontend under `resources/js/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Establish a green baseline before touching the optimize pipeline.

- [X] T001 Run the current CI gate to confirm a clean baseline: `./vendor/bin/pest`, `npm run test`, `npm run lint`, `npm run types`, `npm run format:check`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared TypeScript contracts the edit flow builds on.

**⚠️ CRITICAL**: US1 frontend work depends on these type additions.

- [X] T002 [P] Add edit-flow types to `resources/js/types/tour.ts`: `EditTour` (`{ id, mode, loop, stops: { lat, lng, durationMinutes }[] }`), an optional `tour_id` on the optimize request payload type, and the optional `editTour` page prop. Follow naming-philosophy (full words, "Tour").

**Checkpoint**: Types compile; US1 can begin.

---

## Phase 3: User Story 1 - Edit an optimized tour before assigning it (Priority: P1) 🎯 MVP

**Goal**: Re-open an unassigned tour in the optimize menu pre-filled with its stops/durations/mode/loop, and re-optimize so the same tour is updated in place (no duplicate).

**Independent Test**: Optimize a tour → click Edit → confirm the editing view shows the same stops/durations/mode/loop → change one stop → re-optimize → confirm the same tour id reflects the change and `Tour::count()` is unchanged.

### Tests for User Story 1 ⚠️ (write first, ensure they FAIL)

- [X] T003 [P] [US1] Feature test in `tests/Feature/EditTourOptimizeTest.php`: `POST /api/tour/optimize` with a valid owned unassigned `tour_id` updates that tour + replaces its stops (tour count unchanged, `data.id === tour_id`); an assigned `tour_id` → 422; a foreign/missing `tour_id` → 404; no `tour_id` still creates (regression).
- [X] T004 [P] [US1] Unit test in `tests/Unit/TourRecorderEditTest.php`: `TourRecorder::record` with `editTourId` updates the tour's mode/loop/totals, deletes prior stops, recreates ordered stops; a missing target throws (no create).
- [X] T005 [P] [US1] Feature test in `tests/Feature/EditTourPageTest.php`: `GET /tour/{tour}/edit` for an owned unassigned tour returns the optimize page with an `editTour` prop carrying stops (ascending position, `duration_minutes`), mode, and loop; foreign tour → 404; assigned tour → not editable (redirect/404); plain tour page has `editTour = null`.
- [X] T006 [P] [US1] Vitest in `resources/js/hooks/use-tour-optimization.test.ts`: when seeded with an `editTour`, the hook initializes the stop list from it and includes `tour_id` in the optimize POST body; without one, no `tour_id` is sent; a successful edit re-optimize settles to `state.status === 'done'` (which drives the result view — FR-011).

### Implementation for User Story 1

**Backend — thread the optional `tour_id` through the existing pipeline (update vs create at persistence only):**

- [X] T007 [US1] In `app/Http/Requests/OptimizeTourRequest.php` add a `sometimes|integer` `tour_id` rule validating the tour is owned (foreign/missing → 404, mirror `AssignTourRequest::failedAuthorization`) and unassigned (assigned → 422 with a clear message).
- [X] T008 [US1] In `app/Services/TourRecorder.php` add `?int $editTourId` to `record`; when set, update the existing tour (`delivery_mode_id`, `loop`, `travel_duration_s`, `total_distance_m`), delete its stops, recreate the ordered stops — all in the existing `DB::transaction`; a missing target throws (rolls back, no create). Keep the create branch unchanged.
- [X] T009 [US1] In `app/Services/TourOptimizationService.php` add `?int $editTourId` to `optimize`, pass it into `recordCacheHit` → `record`, and into the `OptimizeTourJob::dispatch(...)` args.
- [X] T010 [US1] In `app/Jobs/OptimizeTourJob.php` add a readonly `?int $editTourId` constructor arg and pass it to `$recorder->record(...)`; the vanished-target case already surfaces via the existing try/catch → `persist_failed` (log stays).
- [X] T011 [US1] In `app/Http/Controllers/TourOptimizationController.php` read `$request->validated('tour_id')` and pass it to `$tours->optimize(...)`.

**Backend — reuse the optimize page for both new and edit:**

- [X] T012 [US1] Create `app/Http/Controllers/TourPageController.php` with `create` (renders `tour/optimize`, `editTour = null`) and `edit(Tour $tour)` (404 if not owned, not-editable if assigned; otherwise renders `tour/optimize` with the `editTour` prop = id/mode(label)/loop/stops mapped to `duration_minutes` in position order).
- [X] T013 [US1] In `routes/web.php` (auth+verified group) replace the `Route::inertia('tour', …)` with `TourPageController@create` and add `Route::get('tour/{tour}/edit', [TourPageController::class, 'edit'])`, preserving the `tour.optimize.page` name.

**Frontend — hydrate the page and send `tour_id` on re-optimize:**

- [X] T014 [US1] In `resources/js/hooks/use-tour-optimization.ts` accept an optional `editTour` seed: initialize `stops` from it (seconds→minutes already done server-side) and hold an `editTourId`; include `tour_id` in the `/api/tour/optimize` body when set. Keep the create path untouched when absent.
- [X] T015 [US1] In `resources/js/pages/tour/optimize.tsx` read `editTour` from `usePage().props`, pass it to `useTourOptimization`, seed `mode`/`loop` from it, and land in the editing view (not the result view) on load.
- [X] T016 [US1] In `resources/js/components/tour/result-summary.tsx` add an **Edit** `ActionButton` between New and Assign that calls `router.visit('/tour/' + result.id + '/edit')` (import `router` from `@inertiajs/react`); disabled while an optimization is pending.

**Checkpoint**: A tour can be edited end-to-end and updates in place — MVP complete and independently testable.

---

## Phase 4: User Story 2 - Relabeled action buttons (Priority: P2)

**Goal**: Result-view actions read **New · Edit · Assign** in that order, with the first and last renamed from "New tour"/"Assign Driver".

**Independent Test**: Open the result view; confirm exactly three buttons labeled "New", "Edit", "Assign" left-to-right; New still confirms+resets, Assign still assigns.

### Tests for User Story 2 ⚠️ (write first)

- [X] T017 [P] [US2] Vitest in `resources/js/components/tour/result-summary.test.tsx`: the action row renders exactly three buttons with accessible names "New", "Edit", "Assign" in that DOM order; New triggers the new-tour confirm, Assign stays disabled until a driver is selected.

### Implementation for User Story 2

- [X] T018 [US2] In `resources/js/components/tour/result-summary.tsx` relabel "New tour" → "New" and "Assign Driver" → "Assign", keeping their existing behavior, with the row ordered New · Edit · Assign. (Same file as T016 — sequence after it.)

**Checkpoint**: Both stories functional and independently testable.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [~] T019 Quickstart verify steps covered by the automated suite (edit updates in place / no duplicate; assigned + foreign tours rejected). A manual in-browser click-through (Optimize → Edit → re-optimize) was NOT run in this environment — recommended before release.
- [X] T020 Run the FULL CI gate before done: `./vendor/bin/pest`, `npm run test`, `npm run lint`, `npm run types`, and `npm run format:check` (format is separate from lint) — all green.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none — start immediately.
- **Foundational (Phase 2)**: after Setup — blocks US1 frontend.
- **US1 (Phase 3)**: after Foundational.
- **US2 (Phase 4)**: after Foundational; its impl task shares `result-summary.tsx` with US1's T016, so T018 runs after T016.
- **Polish (Phase 5)**: after US1 (+US2 for the button test).

### User Story Dependencies

- **US1 (P1)**: independent — delivers the whole edit capability (Edit button included).
- **US2 (P2)**: independent behavior (label/order); only file-level ordering ties it to T016.

### Within User Story 1

- Tests (T003–T006) first, expected to fail.
- Backend: T008 (recorder signature) → T009/T010 (thread it) → T011 (controller). T007 independent.
- Page: T012 → T013.
- Frontend: T002 → T014 → T015; T016 after the page renders.

### Parallel Opportunities

- T003, T004, T005, T006 (different test files) run in parallel.
- T007 (request) and T012 (new controller) are independent of the recorder/service chain — parallelizable with T008.
- T017 (US2 test) parallel with US1 tests.

---

## Parallel Example: User Story 1 tests

```bash
Task: "Feature test optimize tour_id in tests/Feature/EditTourOptimizeTest.php"
Task: "Unit test TourRecorder update path in tests/Unit/TourRecorderEditTest.php"
Task: "Feature test edit page hydration in tests/Feature/EditTourPageTest.php"
Task: "Vitest hook seeds stops + sends tour_id in resources/js/hooks/use-tour-optimization.test.ts"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup → 2. Phase 2 Foundational → 3. Phase 3 US1 → **STOP & VALIDATE** (edit end-to-end, no duplicate) → demo.

### Incremental Delivery

1. Setup + Foundational → ready.
2. US1 → test independently → demo (MVP: editing works, Edit button present).
3. US2 → test independently → demo (buttons read New · Edit · Assign).

---

## Notes

- Keep the change additive: reuse the optimize pipeline, request, service, recorder, and the optimize page — the only new file is `TourPageController`. No schema change.
- Minimal comments (constitution II): one in-body comment is justified where the "update = replace stops in-transaction" invariant isn't obvious; otherwise self-evident code.
- No new colors/styles — reuse `ActionButton` (constitution VI).
- Robustness: the queued-edit path must surface `persist_failed` on a vanished target, never a silent create.
