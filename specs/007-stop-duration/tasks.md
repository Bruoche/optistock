---

description: "Task list for Per-Stop Delivery Duration & Tour Duration Total (007)"
---

# Tasks: Per-Stop Delivery Duration & Tour Duration Total

**Input**: Design documents from `/specs/007-stop-duration/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/optimize-wait-time.md

**Tests**: Included — the constitution (I. Quality First) requires tests for behavior affecting correctness.

**Organization**: Two user stories — US1 (set a delivery duration per stop, P1, frontend-only) and US2 (see
Time on road + Tour duration totals, P2, backend `wait_time` + result display). US2 builds on US1's
`Stop.durationMinutes`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 / US2
- Exact file paths included in each task.

## Path Conventions

Laravel + React monorepo: backend under `app/`, `tests/`; frontend under `resources/js/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: none. No new dependencies, migrations, storage, or routes — this feature only extends the
existing `POST /api/tour/optimize` payload/response and the tour-edit/result UI. Proceed directly to the
user stories.

---

## Phase 2: User Story 1 - Set a delivery duration per stop (Priority: P1) 🎯 MVP

**Goal**: Every stop row in the editor shows an editable minutes field; new stops default to 10; editing one
stop never affects another, and values survive add/remove of other stops.

**Independent Test**: Add several stops → each shows 10 min; change one to 20 and another to 0 → each sticks;
remove a stop → remaining durations unchanged. (No backend involved.)

### Tests for User Story 1 (write first; ensure they FAIL before implementation) ⚠️

- [ ] T001 [P] [US1] Extend the hook test: `addStop` yields `durationMinutes: 10`; a new `setStopDuration(id, minutes)` updates only the target stop (others unchanged) and coerces invalid/negative/non-integer input to a valid non-negative whole number; `removeStop` preserves the remaining stops' durations — in `resources/js/hooks/use-tour-optimization.test.ts`.
- [ ] T002 [P] [US1] Extend the StopList test: each row renders a numeric minutes input defaulting to its stop's `durationMinutes`; editing it calls `onDurationChange(stop.id, minutes)` for that row only; the input is disabled when `locked` — in `resources/js/components/tour/stop-list.test.tsx`.

### Implementation for User Story 1

- [ ] T003 [US1] Add `durationMinutes: number` to the `Stop` type (with the "minutes spent delivering; default 10" note) in `resources/js/types/tour.ts`.
- [ ] T004 [US1] In `useTourOptimization`: set `durationMinutes: 10` in `addStop`; add `setStopDuration(id, minutes)` that updates only the matching stop and coerces to a non-negative whole number (empty/NaN/negative → a valid value); return it from the hook — in `resources/js/hooks/use-tour-optimization.ts` (depends on T003).
- [ ] T005 [US1] Add a per-row minutes `<input>`/`Input` (label e.g. "min", `min=0`, integer) bound to `stop.durationMinutes`, calling an `onDurationChange(id, minutes)` prop; disabled when `locked`; role-named colors + shared `ui` primitive, mirroring the row's existing styling — in `resources/js/components/tour/stop-list.tsx` (depends on T003).
- [ ] T006 [US1] Wire `setStopDuration` from the hook into `<StopList onDurationChange=… />` in `resources/js/pages/tour/optimize.tsx` (depends on T004, T005).

**Checkpoint**: durations are editable per stop, default 10, independent, and survive add/remove.

---

## Phase 3: User Story 2 - See time on road and total tour duration (Priority: P2)

**Goal**: Optimize returns `wait_time_s` (= Σ stop minutes × 60, computed in the controller, not sent
upstream, not cached with the tour); the result shows **Time on road** (existing travel value, `null` →
"Unavailable") and **Tour duration** = `(time on road ?? 0) + wait_time`.

**Independent Test**: Backend — POST optimize with `durations` → response carries `wait_time_s` = sum×60 on
cache miss and cache hit; invalid durations → 422; durations never reach the TSP client. Frontend — a 2-point
tour with durations 15+10 shows Tour duration 25 min before metrics, 45 min after a 20-min road trace.

### Tests for User Story 2 (write first; ensure they FAIL before implementation) ⚠️

- [ ] T007 [P] [US2] Extend the optimize feature test: response includes `wait_time_s` = Σ(durations)×60 on both the 200 (cache hit) and 202 (cache miss) paths; omitting `durations` defaults each stop to 10; size-mismatch / negative / non-integer / >1440 durations → 422; durations are NOT forwarded to the TSP client (fake/spy `OpenStreetTspClient` asserts it receives coordinates only, no durations) — in `tests/Feature/TourOptimizationTest.php`.
- [ ] T008 [P] [US2] Extend the ResultSummary test: renders a **Time on road** figure (`roadMetrics?.duration_s ?? total_duration_s`; `null` → "Unavailable") and a **Tour duration** figure = `(deliveryS ?? 0) + waitTimeS`; an unavailable Time on road yields Tour duration = `waitTimeS` only; supplying `roadMetrics` updates both — in `resources/js/components/tour/result-summary.test.tsx`.
- [ ] T009 [P] [US2] Extend the hook test: `optimize` posts a `durations` array matching the stops' `durationMinutes`; the hook reads `payload.wait_time_s` from both 200 and 202 responses and carries `waitTimeS` into the `done` state, including an async result settled from a broadcast/poll — in `resources/js/hooks/use-tour-optimization.test.ts`.

### Implementation for User Story 2

- [ ] T010 [P] [US2] Add `durations` validation to `OptimizeTourRequest`: optional `array`, size equals `coordinates` when present; `durations.*` `integer|min:0|max:1440`; add matching `messages()` — in `app/Http/Requests/OptimizeTourRequest.php`.
- [ ] T011 [US2] In `optimizeTour`, compute `wait_time_s` = `array_sum($request->validated('durations') ?? array_fill(0, count(coordinates), 10)) * 60` and add it as a sibling of the body in BOTH responses (200 `{status,data,wait_time_s}` and 202 `{status,job_uuid,wait_time_s}`); do NOT pass durations to `TourOptimizationService`/the job/the cache key — in `app/Http/Controllers/TourOptimizationController.php` (depends on T010).
- [ ] T012 [P] [US2] Add `waitTimeS: number` to the `submitting`, `pending`, and `done` variants of `OptimizeState` in `resources/js/types/tour.ts`.
- [ ] T013 [US2] In `useTourOptimization`: send `durations: stops.map((s) => s.durationMinutes)` in the optimize POST; read `payload.wait_time_s`, store it in a ref (like `optimizedMode`/`closeLoop`) and in `state`; thread `waitTimeS` through `submitting`/`pending`/`settleDone` — in `resources/js/hooks/use-tour-optimization.ts` (depends on T012, T004).
- [ ] T014 [US2] In `ResultSummary`: accept `waitTimeS: number`; relabel the existing block "Tour duration" → **"Time on road"** (still `formatDuration(deliveryS)`, keeps "Unavailable"); add a second **"Tour duration"** block = `formatDuration((deliveryS ?? 0) + waitTimeS)`, reusing the existing stat-block markup + role-named colors — in `resources/js/components/tour/result-summary.tsx` (depends on T012).
- [ ] T015 [US2] Pass `state.waitTimeS` into `<ResultSummary waitTimeS=… />` in `resources/js/pages/tour/optimize.tsx` (depends on T014, T013).

**Checkpoint**: both totals render correctly; cache-hit durations stay fresh; duration edits never re-fire the upstream call.

---

## Phase 4: Polish & Cross-Cutting Concerns

- [ ] T016 Run `php artisan test --filter=TourOptimizationTest` and `npm run test -- stop-list result-summary use-tour-optimization` and confirm green; then walk `specs/007-stop-duration/quickstart.md` end-to-end (defaults, two totals, 2-point 0-delivery example, cache freshness, validation).
- [ ] T017 [P] Run `composer ci:check` (lint + format + types + tests) and fix any fallout from the changed types/props.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **User Story 1 (Phase 2)**: can start immediately — the MVP.
- **User Story 2 (Phase 3)**: backend tasks (T007, T010, T011) are fully independent and can start immediately; the frontend US2 tasks depend on US1's `Stop.durationMinutes` (T003) and `setStopDuration` (T004).
- **Polish (Phase 4)**: after US1 + US2.

### Within the stories

- US1: tests T001–T002 fail first → T003 (type) → T004 (hook) ∥ T005 (StopList) → T006 (wiring).
- US2: tests T007–T009 fail first. Backend chain T010 → T011. Frontend chain T012 → (T013 ∥ T014) → T015.

### Parallel Opportunities

- US1 tests T001, T002 in parallel; US2 tests T007, T008, T009 in parallel.
- US2 backend (T010→T011) runs parallel to the US2 frontend chain.
- T012 is [P] (type-only) vs the backend; T017 [P].

---

## Parallel Example: User Story 2

```bash
# Tests first, together:
Task: "Optimize feature test wait_time_s in tests/Feature/TourOptimizationTest.php"          # T007
Task: "ResultSummary two-figures test in resources/js/components/tour/result-summary.test.tsx" # T008
Task: "Hook durations/wait_time_s test in resources/js/hooks/use-tour-optimization.test.ts"   # T009

# Then the two implementation tracks in parallel:
# Backend:  T010 → T011
# Frontend: T012 → (T013 ∥ T014) → T015
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 2 US1 (tests fail → implement → pass): per-stop duration capture.
2. **STOP and VALIDATE**: add/edit/remove stops, confirm defaults + independence. Demo-able on its own.

### Incremental Delivery

1. US1 → durations captured (MVP).
2. US2 → backend `wait_time` + the two result figures → the realistic total tour time.
3. Polish → full suite + quickstart.

---

## Notes

- [P] = different files, no dependencies.
- `wait_time` is computed in the controller and returned in the response, but is NOT part of the optimize
  cache key and is NOT sent to OpenStreet (plan D1) — duration edits on an identical route must not re-fire
  the upstream call.
- `wait_time_s` is carried client-side through `OptimizeState` like `mode`/`loop`; the job, broadcast, and
  status endpoint are untouched (plan D2).
- Seconds end-to-end; both figures reuse `formatDuration`; null Time on road contributes 0 to Tour duration.
- Verify tests fail before implementing; commit after each task or logical group.
