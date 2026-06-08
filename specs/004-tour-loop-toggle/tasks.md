---
description: "Task list for Tour Loop Toggle"
---

# Tasks: Tour Loop Toggle

**Input**: Design documents from `/specs/004-tour-loop-toggle/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/tour-loop.md

**Tests**: INCLUDED — constitution (I. Quality First) + plan enumerate them.

**Organization**: Grouped by user story. US1 (optimize open/closed) and US2 (route omits return) are
both P1; US3 (toggle UI) is P2. The optimize and trace data paths are testable at the API/hook level
without the toggle; US3 adds the user-facing control feeding them.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different files, no incomplete-task dependency.
- **[Story]**: US1 / US2 / US3.

## Path Conventions

Web app (Laravel + React/Inertia): backend `app/`, `tests/`; front `resources/js/`.

---

## Phase 1: Setup

- [x] T001 Confirm the current `tour=closed` behaviour is the `loop=true` default and that no env/config knob is needed (looping default is a domain constant) — basis for FR-002. Reference: `app/Services/OpenStreetTspClient.php` (hard-coded `'tour' => 'closed'`).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared front-end type, depended on by every front story.

**⚠️ CRITICAL**: blocks the front-end story work.

- [x] T002 [P] Add `loop: boolean` to the `submitting` / `pending` / `done` variants of `OptimizeState` in resources/js/types/tour.ts

**Checkpoint**: state shape ready — stories can begin.

---

## Phase 3: User Story 1 - Choose whether the tour returns to the origin (Priority: P1) 🎯 MVP

**Goal**: The optimize flow accepts `loop`, requests `tour=closed|open`, and caches per-shape so a closed
tour is never served to an open request (and vice versa).

**Independent Test**: POST `/api/tour/optimize` with `loop=false` → the TSP query carries `tour=open`,
the job carries `loop=false`, and a previously-cached looped tour for the same stops/mode is not
returned; a bad `loop` value → 422; omitted `loop` → closed default.

### Tests for User Story 1 ⚠️ (write first, ensure they fail)

- [x] T003 [P] [US1] Extend tests/Unit/TourCacheTest.php: same coordinates + same mode but different shape ⇒ distinct `tour:{mode}:{shape}:{hash}` / `tour:active:{userId}:{mode}:{shape}:{hash}` keys (no cross-shape hit). **Update the existing arity-bound assertions/calls** (`test_keys_are_namespaced`, round-trip, active-job tests) for the new shape parameter so the existing suite stays green
- [x] T004 [P] [US1] Extend tests/Feature/TourOptimizationTest.php: 422 on non-boolean `loop`; omitted `loop` → closed default; `loop=false` reaches the TSP query as `tour=open` (faked HTTP) and the dispatched `OptimizeTourJob`; an open request does not return a cached looped tour. **Update the existing `putTour(...)` calls + job assertions** for the new shape arity
- [x] T005 [P] [US1] Extend resources/js/hooks/use-tour-optimization.test.ts: `optimize(mode, loop)` sends `loop` in the body; the `done` state carries the snapshotted `loop`

### Implementation for User Story 1

- [x] T006 [P] [US1] Add a `loop` rule (`sometimes`, `boolean`) to app/Http/Requests/OptimizeTourRequest.php
- [x] T007 [P] [US1] Make the cache methods take a `bool $loop`; the key builders map it to a readable shape segment (`closed`|`open`) internally so keys become `tour:{mode}:{shape}:{hash}` / `tour:active:{userId}:{mode}:{shape}:{hash}`. Add the `bool $loop` parameter to `tourKey`, `activeJobKey`, `getTour`, `putTour`, `claimActiveJob`, `getActiveJob`, `releaseActiveJob` in app/Services/TourCache.php. Callers pass the boolean — the bool→`open`/`closed` translation for the **API** lives in the job (T009), not here
- [x] T008 [P] [US1] Add a `?string $tour = null` arg to `optimize()` that is forwarded straight into the query as `'tour' => $tour ?? 'closed'` (thin client — no bool logic; default `closed` preserves today's behaviour) in app/Services/OpenStreetTspClient.php
- [x] T009 [US1] Add a readonly `bool $loop` ctor arg; **translate it to the OpenStreet string in the job** (`$tour = $this->loop ? 'closed' : 'open'`) and pass that to `OpenStreetTspClient::optimize($coordinates, $this->mode, $tour)`; pass the **boolean** `$this->loop` to the shape-keyed `TourCache` calls in `handle()` + `failed()`; include `loop` in the log context, in app/Jobs/OptimizeTourJob.php. **Update every existing `OptimizeTourJob` construction + `TourCache` call in tests/Feature/TourOptimizationBroadcastTest.php** (the `makeJob` helper, the inline 2-point job, `getTour`/`claimActiveJob`/`getActiveJob`) to pass the new `loop` (depends on T007, T008)
- [x] T010 [US1] Change the signature to `optimize(int $userId, array $coordinates, string $mode, bool $loop)`; pass `loop` to every `TourCache` call and the dispatched `OptimizeTourJob`, in app/Services/TourOptimizationService.php (depends on T007, T009)
- [x] T011 [US1] Read `$request->validated('loop') ?? true` and pass it to the service in app/Http/Controllers/TourOptimizationController.php (depends on T010)
- [x] T012 [US1] Change `optimize` to take `(mode, loop)`, send `loop` in the optimize POST body, and thread it through the `submitting`/`pending`/`done` states in resources/js/hooks/use-tour-optimization.ts (depends on T002). **Add a `closeLoop` ref mirroring the existing `optimizedMode` ref** (same convention — a `useRef` holding the snapshot, no "Ref" suffix; `closeLoop` reads as the predicate it is) so the async settle path (`settleDone`, reached from the Reverb broadcast / status poll) injects the snapshotted `loop` into the `done` state — not just the inline 200 path
- [x] T013 [US1] Hold `loop` state (default `true`) on the page and pass it to `optimize(mode, loop)` (toggle UI lands in US3) in resources/js/pages/tour/optimize.tsx (depends on T012). The loop lives in page state and is **retained across reset** — `reset()` must not clear it (default is first-load only)

**Checkpoint**: optimization is loop-aware end-to-end + per-shape cached. MVP demoable via API/hook.

---

## Phase 4: User Story 2 - See the route drawn without the return segment when looping is off (Priority: P1)

**Goal**: The geometry trace omits the closing leg for an open tour, the drawn route ends at the last
stop, and totals exclude the return — using the loop the tour was optimized with (FR-007).

**Independent Test**: Optimize the same stops looped vs open; confirm the open geometry request carries
`loop=false`, returns one fewer leg, draws no return segment, and reports a smaller total.

### Tests for User Story 2 ⚠️

- [x] T014 [P] [US2] Extend tests/Feature/TourGeometryTest.php: with `loop=false` the service traces one fewer leg (no `last → first`) and totals exclude the return; with `loop=true` behaviour is unchanged. **Update existing `trace(...)` calls** for the new `loop` argument
- [x] T015 [P] [US2] Extend resources/js/hooks/use-tour-geometry.test.ts: the geometry POST body includes `loop`; `loop=false` ⇒ composed `closed` is `false` and no return point is appended

### Implementation for User Story 2

- [x] T016 [P] [US2] Add a `loop` rule (`sometimes`, `boolean`) to app/Http/Requests/TourGeometryRequest.php
- [x] T017 [US2] Change `trace(array $orderedStops, ?string $mode, bool $loop = true)` to build the closing leg (`(i+1) % count`) only when `$loop`; for an open tour iterate legs `0..count-2` so the route ends at the last stop and totals exclude the return, in app/Services/TourGeometryService.php
- [x] T018 [US2] Read `$request->validated('loop') ?? true` and pass it to `trace()` in app/Http/Controllers/TourGeometryController.php (depends on T017)
- [x] T019 [US2] Accept a `loop` parameter, send `{ stops, mode, loop }` in the geometry POST, and set the composed `closed` flag from `loop` in `composeGeometry` in resources/js/hooks/use-tour-geometry.ts (depends on T002)
- [x] T020 [US2] Pass the `done` state's snapshotted `loop` into `useTourGeometry(doneResult, mode, loop)` in resources/js/pages/tour/optimize.tsx (depends on T013, T019)

**Checkpoint**: route shape matches optimization shape; US1+US2 work together.

---

## Phase 5: User Story 3 - Loop toggle defaults to on, beside the mode dropdown (Priority: P2)

**Goal**: A toggle beside the mode dropdown, default on, clear state, feeding the page `loop` state.

**Independent Test**: On first load the toggle reads on and sits next to the mode dropdown; flipping it
shows the off state; it is disabled while optimizing.

### Tests for User Story 3 ⚠️

- [x] T021 [P] [US3] Test `LoopToggle`: default-on display, fires `onChange` with the new value, honors `disabled`, in resources/js/components/tour/loop-toggle.test.tsx

### Implementation for User Story 3

- [x] T022 [P] [US3] Create `LoopToggle` (reuses shadcn `components/ui/toggle.tsx`; `pressed={value}` + `onPressedChange={onChange}`; props `{ value, onChange, disabled }`; label "Loop" when on / "One-way" when off, plus `aria-label="Return to origin"` so its state is testable; role-color classes only) in resources/js/components/tour/loop-toggle.tsx
- [x] T023 [US3] Add `LoopToggle` to the right of `ModeSelect`, with new `loop` + `onLoopChange` props, in resources/js/components/tour/tour-control-bar.tsx (depends on T022)
- [x] T024 [US3] Bind the toggle to the page `loop` state via the control bar and disable it while optimizing, in resources/js/pages/tour/optimize.tsx (depends on T023, T013)

**Checkpoint**: full feature — user-selectable tour shape driving optimization and the drawn route.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [x] T025 [P] Run `php artisan test --filter "TourOptimization|TourCache|TourGeometry|DeliveryMode"`; confirm the 002/003 suites still pass after the shape/arity changes
- [x] T026 [P] Run `npm run test -- use-tour-optimization use-tour-geometry loop-toggle mode-select stop-list optimize`
- [ ] T027 Run quickstart.md manual verification: looped vs open across all three modes, no return segment + smaller total when open, FR-008 (toggle change does not alter a shown tour), 422 on a bad `loop`

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (P1)** → no deps.
- **Foundational (P2)** → after Setup; blocks front-end story tasks (T002).
- **US1 (P3)** → after Foundational. The MVP. (Backend tasks T006–T011 don't need T002.)
- **US2 (P4)** → after Foundational; T020 also needs US1's T013 (shared `optimize.tsx`).
- **US3 (P5)** → after Foundational; T024 also needs US1's T013 (shared `optimize.tsx`).
- **Polish (P6)** → after the stories you intend to ship.

### Key cross-task dependencies

- T009 ← T007,T008; T010 ← T007,T009; T011 ← T010; T012 ← T002; T013 ← T012.
- T017 → T018; T019 ← T002; T020 ← T013,T019.
- T022 → T023 → T024 (← T013).
- **Shared file `resources/js/pages/tour/optimize.tsx`**: T013 → T020 → T024 must be sequential (no [P]).

### Parallel Opportunities

- US1 tests T003/T004/T005 in parallel; impl T006/T007/T008 in parallel (different files) before T009.
- US2: T014/T015 in parallel; T016 in parallel; then T017→T018, T019→T020.
- US3: T021/T022 in parallel start; then T023→T024.

---

## Parallel Example: User Story 1

```bash
# Tests first (different files):
Task: "Extend TourCacheTest.php for shape-keyed entries"
Task: "Extend TourOptimizationTest.php for loop validation + tour=open|closed threading"
Task: "Extend use-tour-optimization.test.ts for loop in body + done state"

# Then independent implementation files in parallel:
Task: "Add loop rule to OptimizeTourRequest.php"
Task: "Thread shape into TourCache.php keys"
Task: "Map loop → tour=closed|open in OpenStreetTspClient.php"
```

---

## Implementation Strategy

### MVP First (US1)

1. Setup (T001) → Foundational (T002) → US1 (T003–T013).
2. **STOP & VALIDATE**: optimize looped vs open via API/hook; confirm `tour=open|closed`, per-shape
   caching, 422.

### Incremental Delivery

1. Foundation ready → US1 (loop-aware optimization, MVP).
2. US2 → route omits the return segment + totals exclude it when open.
3. US3 → user-facing toggle (default on, beside the mode dropdown, disabled while optimizing).
4. Polish → full test sweep + quickstart.

---

## Notes

- [P] = different files, no incomplete-task dependency.
- The loop bool maps to the TSP `tour` field (`closed`|`open`) in **one** place — the **job** (`OptimizeTourJob`); the client just forwards the string. The HTTP request and the cache stay boolean.
- The cache **must** be shape-keyed (T007), else an open request hits a cached looped tour.
- `RouteLayer` already has a `closed` prop and `composeGeometry` a `closed` flag — they follow `loop`.
- Update existing tests in lockstep with each signature change (T003/T004/T009/T014) — the 003 lesson.
- Commit after each task or logical group; keep the 002/003 suites green.
