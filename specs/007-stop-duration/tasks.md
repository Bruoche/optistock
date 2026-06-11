---
description: "Task list for Per-Stop Delivery Duration & Tour Duration Total (007)"
---

# Tasks: Per-Stop Delivery Duration & Tour Duration Total

**Input**: Design documents from `/specs/007-stop-duration/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/optimize-wait-time.md

**Tests**: Included — the constitution (I. Quality First) requires tests for behavior affecting correctness.

**Scope**: **Frontend-only.** No backend, request, response, job, broadcast, cache, or `config/` change. The
stop total (`waitTimeS`) is computed in the browser from the stops' `durationMinutes` and shown in the result.

**Organization**: Two user stories — US1 (set a delivery duration per stop, P1) and US2 (see Time on road +
Tour duration totals, P2). US2 builds on US1's `Stop.durationMinutes`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 / US2
- Exact file paths included in each task.

## Path Conventions

React frontend under `resources/js/`. No backend files are touched by this feature.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: none. No new dependencies, migrations, storage, routes, or API changes — this feature only
extends the tour-edit/result UI and the hook that owns stop state. Proceed directly to the user stories.

---

## Phase 2: User Story 1 - Set a delivery duration per stop (Priority: P1) 🎯 MVP

**Goal**: Every stop row in the editor shows an editable minutes field; new stops default to 10; editing one
stop never affects another, and values survive add/remove of other stops.

**Independent Test**: Add several stops → each shows 10 min; change one to 20 and another to 0 → each sticks;
remove a stop → remaining durations unchanged.

### Tests for User Story 1 (write first; ensure they FAIL before implementation) ⚠️

- [ ] T001 [P] [US1] Extend the hook test: `addStop` yields `durationMinutes: 10`; a new `setStopDuration(id, minutes)` updates only the target stop (others unchanged) and coerces invalid input (empty/NaN/negative → 0, non-integers floored, > 1440 clamped to 1440); `removeStop` preserves the remaining stops' durations — in `resources/js/hooks/use-tour-optimization.test.ts`.
- [ ] T002 [P] [US1] Extend the StopList test: each row renders a numeric minutes input defaulting to its stop's `durationMinutes`; editing it calls `onDurationChange(stop.id, minutes)` for that row only; the input is disabled when `locked` — in `resources/js/components/tour/stop-list.test.tsx`.

### Implementation for User Story 1

- [ ] T003 [US1] In `resources/js/types/tour.ts`: add `durationMinutes: number` to the `Stop` type (with the "minutes spent delivering; default 10" note) and export `DEFAULT_STOP_DURATION_MINUTES = 10` and `MAX_STOP_DURATION_MINUTES = 1440`.
- [ ] T004 [US1] In `useTourOptimization`: set `durationMinutes: DEFAULT_STOP_DURATION_MINUTES` in `addStop`; add `setStopDuration(id, minutes)` that updates only the matching stop and coerces to a non-negative whole number — **empty/NaN/negative → `0`; non-integers floored; > 1440 clamped to 1440** (CR-2); return it from the hook — in `resources/js/hooks/use-tour-optimization.ts` (depends on T003).
- [ ] T005 [US1] Add a per-row minutes `<input>`/`Input` (label e.g. "min", `min=0`, `max=1440`, integer) bound to `stop.durationMinutes`, calling an `onDurationChange(id, minutes)` prop; disabled when `locked`; role-named colors + shared `ui` primitive, mirroring the row's existing styling — in `resources/js/components/tour/stop-list.tsx` (depends on T003).
- [ ] T006 [US1] Wire `setStopDuration` from the hook into `<StopList onDurationChange=… />` in `resources/js/pages/tour/optimize.tsx` (depends on T004, T005).

**Checkpoint**: durations are editable per stop, default 10, independent, and survive add/remove.

---

## Phase 3: User Story 2 - See time on road and total tour duration (Priority: P2)

**Goal**: The hook derives `waitTimeS` (= Σ stop minutes × 60) from its `stops`; the result shows **Time on
road** (existing travel value, `null` → "Unavailable") and **Tour duration** = `(time on road ?? 0) +
waitTimeS`. No backend involvement.

**Independent Test**: A 2-point tour with durations 15+10 shows Tour duration 25 min before metrics, 45 min
after a 20-min road trace; Time on road keeps "Unavailable" until the trace lands.

### Tests for User Story 2 (write first; ensure they FAIL before implementation) ⚠️

- [ ] T007 [P] [US2] Extend the ResultSummary test: renders a **Time on road** figure (`roadMetrics?.duration_s ?? total_duration_s`; `null` → "Unavailable") and a **Tour duration** figure = `(deliveryS ?? 0) + waitTimeS`; an unavailable Time on road yields Tour duration = `waitTimeS` only; supplying `roadMetrics` updates both — in `resources/js/components/tour/result-summary.test.tsx`. **Add the new required `waitTimeS` prop to the existing render call here, and confirm every other `<ResultSummary>` call site still compiles (C1).**
- [ ] T008 [P] [US2] Extend the hook test: `waitTimeS` exposed by the hook equals `Σ(durationMinutes) × 60` and tracks edits via `setStopDuration` and add/remove of stops; the optimize POST body stays `{ coordinates, mode, loop }` with **no** `durations` field — in `resources/js/hooks/use-tour-optimization.test.ts`.

### Implementation for User Story 2

- [ ] T009 [US2] In `useTourOptimization`: expose a derived `waitTimeS = stops.reduce((s, st) => s + st.durationMinutes, 0) * 60` from the hook’s return. Do **not** add it to `OptimizeState`, send it in the POST, or snapshot it in a ref (stops are frozen between submit and `done`) — in `resources/js/hooks/use-tour-optimization.ts` (depends on T004).
- [ ] T010 [US2] In `ResultSummary`: accept `waitTimeS: number`; relabel the existing block "Tour duration" → **"Time on road"** (still `formatDuration(deliveryS)`, keeps "Unavailable"); add a second **"Tour duration"** block = `formatDuration((deliveryS ?? 0) + waitTimeS)`, reusing the existing stat-block markup + role-named colors — in `resources/js/components/tour/result-summary.tsx`.
- [ ] T011 [US2] Pass the hook’s `waitTimeS` into `<ResultSummary waitTimeS=… />` in `resources/js/pages/tour/optimize.tsx` (depends on T010, T009).

**Checkpoint**: both totals render correctly; durations never leave the browser; the optimize request/response are unchanged.

---

## Phase 4: Polish & Cross-Cutting Concerns

- [ ] T012 Run `npm run test -- stop-list result-summary use-tour-optimization` and confirm green; then walk `specs/007-stop-duration/quickstart.md` end-to-end (defaults, two totals, 2-point 0-delivery example, durations never hit the backend, client coercion).
- [ ] T013 [P] Run `composer ci:check` (lint + format + types + tests) and fix any fallout from the changed types/props.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **User Story 1 (Phase 2)**: can start immediately — the MVP.
- **User Story 2 (Phase 3)**: depends on US1's `Stop.durationMinutes` (T003) and `setStopDuration` (T004).
- **Polish (Phase 4)**: after US1 + US2.

### Within the stories

- US1: tests T001–T002 fail first → T003 (type/constants) → T004 (hook) ∥ T005 (StopList) → T006 (wiring).
- US2: tests T007–T008 fail first → T009 (derived `waitTimeS`) ∥ T010 (ResultSummary) → T011 (wiring).

### Parallel Opportunities

- US1 tests T001, T002 in parallel; US2 tests T007, T008 in parallel.
- T009 (hook) and T010 (ResultSummary) are different files → parallel.
- T013 [P].

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 2 US1 (tests fail → implement → pass): per-stop duration capture.
2. **STOP and VALIDATE**: add/edit/remove stops, confirm defaults + independence. Demo-able on its own.

### Incremental Delivery

1. US1 → durations captured (MVP).
2. US2 → the derived `waitTimeS` + the two result figures → the realistic total tour time.
3. Polish → full suite + quickstart.

---

## Notes

- [P] = different files, no dependencies.
- **Frontend-only**: the stop total is computed in the browser (plan D1); the backend, the optimize request/
  response, the job, the broadcast, the status endpoint, and `TourCache` are all untouched — a duration edit
  can never reach or re-fire the upstream TSP call.
- `waitTimeS` is **derived live** from `stops`, not carried through `OptimizeState` (stops are frozen between
  submit and `done`) (plan D2).
- Seconds end-to-end; both figures reuse the local `formatDuration`; null Time on road contributes 0 to Tour
  duration.
- Verify tests fail before implementing; commit after each task or logical group.
