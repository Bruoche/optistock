---
description: "Task list for Delivery Mode Selection"
---

# Tasks: Delivery Mode Selection

**Input**: Design documents from `/specs/003-delivery-mode-selection/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/delivery-mode.md

**Tests**: INCLUDED — the constitution (I. Quality First) mandates tests for behavior affecting
correctness, and plan.md enumerates them.

**Organization**: Grouped by user story. US1 and US2 are both P1; US3 is P2. The optimize data path
(US1) and trace data path (US2) are testable at the API/hook level without the dropdown; US3 adds the
user-facing selector that feeds them.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1 / US2 / US3

## Path Conventions

Web app (Laravel + React/Inertia): backend under `app/`, `config/`, `tests/`; front under `resources/js/`.

---

## Phase 1: Setup

**Purpose**: Confirm the shared baseline before threading mode through.

- [ ] T001 Confirm `services.openstreet.mode` stays `trucking` as the omitted-mode fallback (no change) in config/services.php — this is the basis for FR-010 (config becomes fallback, not sole source).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The single source of allowed modes (backend + front), depended on by every story.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [ ] T002 [P] Create string-backed enum `App\Enums\DeliveryMode` (`Trucking='trucking'`, `Driving='driving'`, `Walking='walking'`) with a `default(): self` helper returning `Trucking`, in app/Enums/DeliveryMode.php
- [ ] T003 Unit-test enum cases, backing values, and `default()` in tests/Unit/DeliveryModeTest.php (depends on T002)
- [ ] T004 [P] Add `DeliveryMode` TS union + ordered `DELIVERY_MODES` (`{value,label}[]`) list in resources/js/types/tour.ts

**Checkpoint**: Mode vocabulary exists on both sides — stories can begin.

---

## Phase 3: User Story 1 - Choose a delivery mode and optimize the tour for it (Priority: P1) 🎯 MVP

**Goal**: The optimize flow accepts a `mode`, optimizes for it, and caches per-mode so a different mode
never returns a prior mode's tour.

**Independent Test**: POST `/api/tour/optimize` with `mode=walking` for stops previously optimized as
`trucking`; confirm the TSP request carries `mode=walking` and the result is a fresh (not cross-mode
cached) tour; a bad mode returns 422; an omitted mode uses the config default.

### Tests for User Story 1 ⚠️ (write first, ensure they fail)

- [ ] T005 [P] [US1] Extend tests/Unit/TourCacheTest.php: identical coordinates + different mode ⇒ distinct `tour:{mode}:{hash}` and `tour:active:{userId}:{mode}:{hash}` keys; a put under one mode is not returned for another (no cross-mode hit). **Also update the existing arity-bound assertions/calls for the new `mode` parameter** (`test_keys_are_namespaced` expected keys, and the `getTour`/`putTour`/`claimActiveJob`/`getActiveJob`/`releaseActiveJob` calls in the round-trip and active-job tests) so the existing suite stays green
- [ ] T006 [P] [US1] Extend tests/Feature/TourOptimizationTest.php: 422 on out-of-set mode; omitted mode falls back to config default; the chosen mode reaches the TSP query (faked HTTP) and the dispatched `OptimizeTourJob`; a `walking` request does not return a cached `trucking` tour
- [ ] T007 [P] [US1] Extend resources/js/hooks/use-tour-optimization.test.ts: `optimize(mode)` sends `mode` in the POST body; the `done` state carries the snapshotted `mode`

### Implementation for User Story 1

- [ ] T008 [P] [US1] Add `mode` rule (`sometimes`, `Rule::enum(DeliveryMode::class)`) + a `mode` message to app/Http/Requests/OptimizeTourRequest.php
- [ ] T009 [P] [US1] Thread `string $mode` into the key builders and operations (`tourKey`, `activeJobKey`, `getTour`, `putTour`, `claimActiveJob`, `getActiveJob`, `releaseActiveJob`) so keys become `tour:{mode}:{hash}` / `tour:active:{userId}:{mode}:{hash}` in app/Services/TourCache.php
- [ ] T010 [P] [US1] Add a `?string $mode = null` override to `optimize()` using `$mode ?? $this->mode` in the TSP query (mirror `OpenStreetRouteClient`) in app/Services/OpenStreetTspClient.php
- [ ] T011 [US1] Add a readonly `string $mode` ctor arg; pass it to `OpenStreetTspClient::optimize($coordinates, $this->mode)`, to the mode-keyed `TourCache` calls in both `handle()` and `failed()`, and into the log context, in app/Jobs/OptimizeTourJob.php (depends on T009, T010). **Update every existing `OptimizeTourJob` construction and mode-keyed `TourCache` call in tests/Feature/TourOptimizationBroadcastTest.php** (the `makeJob` helper, the inline 2-point job, and `getTour`/`claimActiveJob`/`getActiveJob`) to pass the new `mode` so the existing broadcast suite stays green
- [ ] T012 [US1] Change signature to `optimize(int $userId, array $coordinates, string $mode)`; pass `mode` to every `TourCache` call and to the dispatched `OptimizeTourJob`, in app/Services/TourOptimizationService.php (depends on T009, T011)
- [ ] T013 [US1] Read `$request->validated('mode') ?? config('services.openstreet.mode')` and pass it to the service in app/Http/Controllers/TourOptimizationController.php (depends on T012)
- [ ] T014 [US1] Change `optimize` to take a `mode` arg, send it in the optimize POST body, and thread it through the `submitting`/`pending`/`done` states in resources/js/hooks/use-tour-optimization.ts (depends on T004)
- [ ] T015 [US1] Hold `mode` state (default `'trucking'`) on the page and pass it to `optimize(mode)` (dropdown UI lands in US3) in resources/js/pages/tour/optimize.tsx (depends on T014). The mode lives in page state and is **retained across reset** — `reset()` must not clear it (trucking is only the first-load default, per FR-003)

**Checkpoint**: Optimization is mode-aware end-to-end and per-mode cached. MVP demoable via API/hook.

---

## Phase 4: User Story 2 - See the road path drawn for the selected mode (Priority: P1)

**Goal**: The geometry trace runs for the same mode the tour was optimized with, so the polyline matches
(FR-007). Trace backend is already mode-ready (002); the front must send the snapshotted mode.

**Independent Test**: For a tour optimized as `walking`, confirm the `/api/tour/geometry` request body
carries `mode=walking` and the drawn polyline is the walking trace; changing the dropdown afterward does
not re-trace the shown tour.

### Tests for User Story 2 ⚠️

- [ ] T016 [P] [US2] Extend resources/js/hooks/use-tour-geometry.test.ts: the geometry POST body includes `mode`, equal to the result's snapshotted mode

### Implementation for User Story 2

- [ ] T017 [P] [US2] Swap the literal `in:driving,walking,trucking` rule for `Rule::enum(DeliveryMode::class)` (keep `sometimes`) in app/Http/Requests/TourGeometryRequest.php; ensure tests/Feature/TourGeometryTest.php still passes (depends on T002)
- [ ] T018 [US2] Accept a `mode` parameter and send `{ stops, mode }` in the geometry POST in resources/js/hooks/use-tour-geometry.ts (depends on T004)
- [ ] T019 [US2] Pass the `done` state's snapshotted `mode` into `useTourGeometry(doneResult, mode)` in resources/js/pages/tour/optimize.tsx (depends on T015, T018)

**Checkpoint**: Polyline mode always matches optimization mode; US1+US2 work together.

---

## Phase 5: User Story 3 - Trucking default with a visible current selection (Priority: P2)

**Goal**: A dropdown beneath the map, left of the Optimize button, defaults to Trucking and always shows
the active mode; it feeds the page mode state from US1/US2.

**Independent Test**: On first load the dropdown reads Trucking and sits left of the Optimize button;
selecting Driving shows Driving; the dropdown is disabled while a tour is optimizing.

### Tests for User Story 3 ⚠️

- [ ] T020 [P] [US3] Test `ModeSelect`: defaults to Trucking, renders the three `DELIVERY_MODES` options, fires `onChange` with the chosen value, honors `disabled`, in resources/js/components/tour/mode-select.test.tsx

### Implementation for User Story 3

- [ ] T021 [P] [US3] Create `ModeSelect` (reuses shadcn `components/ui/select.tsx`; props `{ value, onChange, disabled }`; trucking default; options from `DELIVERY_MODES`; role-color classes only) in resources/js/components/tour/mode-select.tsx (depends on T004)
- [ ] T022 [US3] Create the control bar (flex row: `ModeSelect` left + the Optimize button right) in resources/js/components/tour/tour-control-bar.tsx (depends on T021)
- [ ] T023 [US3] Remove the Optimize button from `StopList` (now in the control bar), keeping the list, and update resources/js/components/tour/stop-list.test.tsx accordingly — file: resources/js/components/tour/stop-list.tsx
- [ ] T024 [US3] Render the control bar **only in the editing view** (not once a result is displayed — `ResultSummary` takes over), bind the dropdown to the page `mode` state, and disable it while optimizing, in resources/js/pages/tour/optimize.tsx (depends on T022, T023, T015)

**Checkpoint**: Full feature — user-selectable mode driving both optimization and tracing.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [ ] T025 [P] Run `php artisan test --filter "TourOptimization|TourCache|DeliveryMode|TourGeometry"`; confirm the 002 trace suite still passes after the enum swap (T017)
- [ ] T026 [P] Run `npm run test -- use-tour-optimization use-tour-geometry mode-select stop-list`
- [ ] T027 Run quickstart.md manual verification: all three modes optimize+trace, FR-007 congruence, FR-008 (dropdown change does not alter shown tour), 422 on bad mode, unreachable-host fallback
- [ ] T028 Add a test asserting the control bar / mode dropdown is **absent** once a result is shown (editing-only, per the resolved FR-004) in resources/js/pages/tour/optimize.test.tsx (or extend mode-select/optimize coverage)

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (P1)** → no deps.
- **Foundational (P2)** → after Setup; **blocks all stories** (T002 enum, T004 TS types).
- **US1 (P3)** → after Foundational. The MVP.
- **US2 (P4)** → after Foundational; T019 also needs US1's T015 (shared `optimize.tsx`).
- **US3 (P5)** → after Foundational; T024 also needs US1's T015 (shared `optimize.tsx`).
- **Polish (P6)** → after the stories you intend to ship.

### Key cross-task dependencies

- T003 ← T002; T011 ← T009,T010; T012 ← T009,T011; T013 ← T012; T014 ← T004; T015 ← T014.
- T017 ← T002; T018 ← T004; T019 ← T015,T018.
- T021 ← T004; T022 ← T021; T024 ← T022,T023,T015.
- **Shared file `resources/js/pages/tour/optimize.tsx`**: T015 → T019 → T024 must be sequential (no [P]).

### Parallel Opportunities

- Foundational: T002 and T004 in parallel (T003 after T002).
- US1 tests T005/T006/T007 in parallel; impl T008/T009/T010 in parallel (different files) before T011.
- US2: T016 (test) parallel with T017 (backend); T018 then T019.
- US3: T020/T021 in parallel start; then T022 → T024.

---

## Parallel Example: User Story 1

```bash
# Tests first (different files):
Task: "Extend TourCacheTest.php for mode-keyed entries"
Task: "Extend TourOptimizationTest.php for mode validation + threading"
Task: "Extend use-tour-optimization.test.ts for mode in body + done state"

# Then independent implementation files in parallel:
Task: "Add mode rule to OptimizeTourRequest.php"
Task: "Thread mode into TourCache.php keys"
Task: "Add mode override to OpenStreetTspClient.php"
```

---

## Implementation Strategy

### MVP First (US1)

1. Setup (T001) → Foundational (T002–T004) → US1 (T005–T015).
2. **STOP & VALIDATE**: optimize with each mode via API/hook; confirm per-mode caching + 422.

### Incremental Delivery

1. Foundation ready → US1 (mode-aware optimization, MVP).
2. US2 → polyline matches the optimization mode.
3. US3 → user-facing dropdown (trucking default, visible, disabled while optimizing).
4. Polish → full test sweep + quickstart + resolve F1.

---

## Notes

- [P] = different files, no incomplete-task dependency.
- The backend trace path (002) is already mode-ready; US2 is mostly the front sending the snapshotted mode.
- The cache **must** be mode-keyed (T009) — without it a walking request returns a cached trucking tour.
- Commit after each task or logical group; keep the 002 trace suite green when adopting the enum (T017).
