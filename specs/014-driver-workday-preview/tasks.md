# Tasks: Driver Workday Preview

**Input**: Design documents from `specs/014-driver-workday-preview/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/driver-workday.md, quickstart.md

**Tests**: Included — the constitution mandates tests for new behavior (Additional Constraints). Each test task precedes its implementation; write it first and see it fail.

**Organization**: Grouped by user story. Phases run in spec priority order: US1 (P1), US2 (P1), US4 (P1), then US3 (P2).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 = map preview on driver click, US2 = Assign Driver button, US3 = segment styling, US4 = instant render + race-safe cycling

## Phase 1: Setup

No setup tasks — the feature extends the existing Laravel + React app on branch `014-driver-workday-preview`; toolchain, palette system, and endpoints are already in place. No migrations, no new routes, no new dependencies.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: the legs-bearing drivers payload (backend) and its frontend plumbing. US1, US3, and US4 all consume `Driver.legs`; only US2 could proceed without it.

**⚠️ CRITICAL**: complete before any user story phase.

- [ ] T001 Extend `tests/Unit/OpenStreetRouteClientTest.php` with failing `legFromResponse` cases: successful body → decoded coordinates + metrics; failed HTTP response → null; non-OK status → null; missing polyline → null (never throws)
- [ ] T002 Implement `legFromResponse(Response): ?array` in `app/Services/OpenStreetRouteClient.php`, sharing the `mapToLeg` parsing; remove `durationFromResponse` (fold into the new method; update its only caller) — plan D6
- [ ] T003 Extend `tests/Unit/TravelTimeServiceTest.php` (failing): connection geometry cached alongside duration from one parse; `geometryBetween` returns decoded coordinates when routed, null when unroutable and null for coincident points; existing dedup + chunk-to-cap assertions unchanged
- [ ] T004 Widen the `TravelTimeService` cache to `{duration_s, coordinates}` and add `geometryBetween(Coordinate, Coordinate, ?string): ?array` in `app/Services/TravelTimeService.php`; `fetchBatch` uses `legFromResponse`; `durationBetween` contract untouched — plan D6
- [ ] T005 [P] Create `WorkdayLeg` value object (`kind`, `dotted`, `path`, `geometry`, `loop` + payload serialization) in `app/Services/WorkdayLeg.php` — data-model.md
- [ ] T006 [P] Create `PriorTourLeg` value object (`start`, `end`, `loop`, `stopCoordinates`) in `app/Services/PriorTourLeg.php` — data-model.md
- [ ] T007 Create `tests/Unit/WorkdayLegsBuilderTest.php` (failing): chain order (connection, tour, connection, …, connection into candidate start, connection candidate end → warehouse); `dotted` true only on connections; connection geometry attached from `TravelTimeService`, null when unroutable; loop tour entered at stop *k* → path rotated to start at *k* with `loop: true`; one-way reversed when recorded start is the last stop, `loop: false`; unmatched pivot start → unrotated path + `warning` log; no prior tours → exactly two connection legs; coincident connection → `path: [p, p]`, `geometry: null`
- [ ] T008 Implement `WorkdayLegsBuilder::build(warehouse, priorTours, candidateStart, candidateEnd, mode): list<WorkdayLeg>` in `app/Services/WorkdayLegsBuilder.php` — plan D3/D4/D5
- [ ] T009 Extend `app/Http/Controllers/DriverController.php`: widen the prior-assignments query set to also load each prior tour's `loop` and ordered stop coordinates (grouped queries, no N+1); assemble `PriorTourLeg`s; emit `legs` per driver via `WorkdayLegsBuilder` alongside the unchanged 013 fields
- [ ] T010 Extend `tests/Feature/DriverAvailabilityTest.php` (failing first): `legs` present with contract shape and chain order (contracts/driver-workday.md); connection legs carry geometry decoded from the pooled `Http::fake` responses; prior-tour legs carry `geometry: null` + rotated `path` + `loop`; **`/route` call count unchanged** vs 013; DB query count pinned (`expectsDatabaseQueryCount`) so the widened prior-assignments query set cannot regress into an N+1; `projected_seconds`/`projected_incomplete`/`start_index`/`warehouse_name` untouched
- [ ] T011 [P] Add the `WorkdayLeg` type and `Driver.legs` field to `resources/js/types/tour.ts` — data-model.md
- [ ] T012 Map `legs` from the payload in `resources/js/hooks/use-tour-drivers.ts`

**Checkpoint**: `GET /api/tour/drivers` serves legs per contract; frontend `Driver` objects carry them. Nothing visible yet.

---

## Phase 3: User Story 1 — Preview a driver's projected workday on the map (Priority: P1) 🎯 MVP

**Goal**: clicking a driver draws their whole projected day (warehouse → tours → candidate → warehouse) on the map instead of opening the pop-up; clicking another driver switches; re-click deselects.

**Independent Test**: with a driver holding a prior tour, click them — the map shows the full chain around the still-highlighted candidate tour; re-click reverts to the candidate only (spec US1 scenarios).

- [ ] T013 [P] [US1] Define the `--route-neutral` role variable in both theme blocks of `resources/css/app.css` (dark neutral in both — map tiles stay light in dark mode; plan D9)
- [ ] T014 [P] [US1] Create `resources/js/components/tour/workday-layer.test.tsx` (failing): one line rendered per leg; road `geometry` used when present, straight `path` fallback when null; color resolved from `--route-neutral`
- [ ] T015 [US1] Create `resources/js/components/tour/workday-layer.tsx`: GeoJSON source/layer per leg drawing `geometry ?? path`, neutral color runtime-resolved like `RouteLayer.primaryColor()`
- [ ] T016 [US1] Rework `resources/js/components/tour/driver-list.tsx`: accept `selectedDriver`/`onSelect` props; row click toggles selection (select / re-click deselects); selected-row styling via existing role classes; remove the direct `AssignDriverDialog` open-on-click
- [ ] T017 [US1] Update `resources/js/components/tour/driver-list.test.tsx`: click selects without opening a dialog; re-click deselects; selected styling asserted; fixtures gain `legs`
- [ ] T018 [US1] Thread selection through `resources/js/components/tour/result-summary.tsx` (pass `selectedDriver`/`onSelect` down to `DriverList`) and update `resources/js/components/tour/result-summary.test.tsx` fixtures
- [ ] T019 [US1] Wire `resources/js/pages/tour/optimize.tsx`: `selectedDriver` state; render `<WorkdayLayer>` inside `TourMap` **before** the candidate `RouteLayer`; clear the selection on reset, on date change, and when the driver list reloads (spec FR-012)

**Checkpoint**: full preview visible on click (prior tours as straight lines, connections road-accurate); assignment temporarily unreachable until US2 lands — ship US1+US2 together.

---

## Phase 4: User Story 2 — Assign via the "Assign Driver" button (Priority: P1) 🎯 MVP

**Goal**: an Assign Driver button right of "New tour", disabled without a selection, opens the unchanged 012 confirmation dialog.

**Independent Test**: no selection → button grayed out; select a driver → click button → familiar pop-up; cancel keeps the preview, confirm assigns and returns to the cleared creation menu (spec US2 scenarios).

- [ ] T020 [US2] Add the **Assign Driver** `ActionButton` to the right of "New tour" in `resources/js/components/tour/result-summary.tsx`: disabled while `selectedDriver` is null; clicking opens `AssignDriverDialog` (moved here from `driver-list.tsx`) for the selected driver with the existing `tourId`/`date`/`startIndex` props; extend `resources/js/components/action-button.tsx` with a disabled state only if it lacks one
- [ ] T021 [US2] Extend `resources/js/components/tour/result-summary.test.tsx`: button present and disabled without selection; enabled with one; opens the dialog; while the dialog is open, driver-row clicks do not change the selection (012 one-confirmation-at-a-time rule); cancel closes without clearing the selection; confirm fires `onAssigned`

**Checkpoint**: complete assign flow — select, button, confirm — behaves as 012 did. MVP done (US1 + US2).

---

## Phase 5: User Story 4 — Instant preview that survives rapid driver cycling (Priority: P1)

**Goal**: `geometry: null` legs upgrade from straight lines to road paths via lazy traces; late responses for deselected drivers never draw; re-selection reuses fetched paths.

**Independent Test**: click through drivers faster than traces resolve — display always matches the last click, placeholders upgrade in place, re-select shows road paths with no refetch (spec US4 scenarios).

- [ ] T022 [P] [US4] Create `resources/js/hooks/use-workday-preview.test.ts` (failing): returns legs synchronously with straight fallbacks; traces only `geometry: null` legs; request body is `{stops: leg.path, mode, loop: leg.loop}` with **no `tour_id`**; a failed trace (network error or `ok: false` response leg) keeps that leg's straight `path` and leaves the other legs intact (FR-011); a response arriving after the selection switched is dropped; re-selecting a traced driver hits the cache (no second fetch); cache cleared when the driver list reloads
- [ ] T023 [US4] Implement `resources/js/hooks/use-workday-preview.ts`: token/identity guard per selected driver (the `useTourGeometry` FR-010 pattern), `driverId:legIndex` ref cache, per-response leg composition (failed trace hop keeps its straight segment); a swallowed trace failure carries a short comment noting the failure is already logged server-side by the geometry endpoint (constitution IV) — plan D8
- [ ] T024 [US4] Integrate the hook in `resources/js/pages/tour/optimize.tsx`: feed `WorkdayLayer` the hook's best-available legs instead of the raw payload legs

**Checkpoint**: rapid cycling safe by construction; prior tours snap to road paths when traces land.

---

## Phase 6: User Story 3 — Distinguish the candidate tour from the rest of the day (Priority: P2)

**Goal**: connections dashed, tour legs solid, everything neutral; candidate tour stays the highlight color on top.

**Independent Test**: preview with a prior tour shows three renderings — highlight-solid candidate, neutral-solid prior tour, neutral-dashed connections (spec US3 scenarios).

- [ ] T025 [US3] Add dashed rendering in `resources/js/components/tour/workday-layer.tsx`: `line-dasharray` on legs with `dotted: true`, solid otherwise (mount order under the candidate `RouteLayer` is already wired by T019 — no re-wiring here)
- [ ] T026 [US3] Extend `resources/js/components/tour/workday-layer.test.tsx`: dashed vs solid driven by `leg.dotted`; candidate layer not rendered by `WorkdayLayer` (only neutral legs); `WorkdayLayer` renders before the candidate `RouteLayer` in the map children (T019 ordering asserted)

**Checkpoint**: all four stories functional.

---

## Phase 7: Polish & Cross-Cutting Concerns

- [ ] T027 [P] Run the backend suite (`php artisan test`) and fix regressions
- [ ] T028 [P] Run the frontend suite + type/lint checks (`npm test`, repo lint/format scripts) and fix regressions
- [ ] T029 Execute the `specs/014-driver-workday-preview/quickstart.md` walkthrough end-to-end, including the rapid-cycling and routing-API-down checks

---

## Dependencies & Execution Order

### Phase Dependencies

- **Foundational (Phase 2)**: starts immediately (no setup). Blocks US1, US3, US4. Internal order: T001→T002→T003→T004 (client before service), T005/T006 [P] anytime, T007→T008, then T009→T010 (controller needs builder + service), T011→T012.
- **US1 (Phase 3)**: after Phase 2. T013/T014 [P] first, T015 after T014, T016→T017, T018 after T016, T019 last (wires everything).
- **US2 (Phase 4)**: only needs US1's selection threading (T016/T018) — can start once those land.
- **US4 (Phase 5)**: after US1 (hook feeds the layer). T022 before T023; T024 last.
- **US3 (Phase 6)**: after US1 (styles the existing layer); independent of US2/US4.
- **Polish (Phase 7)**: after all story phases.

### Story Dependency Notes

US1 is the trunk: US2, US3, and US4 each build on its selection state / layer but not on each other — they can proceed in parallel after Phase 3.

### Parallel Opportunities

- Phase 2: T005 ∥ T006 ∥ T011 (distinct files, no interdependency).
- Phase 3: T013 ∥ T014 while T016 is in progress (different files).
- After Phase 3: US2 (T020–T021) ∥ US4 (T022–T024) ∥ US3 (T025–T026) across developers — T024 and T025 both touch `optimize.tsx`/`workday-layer.tsx`, so within one pair coordinate or serialize.
- Phase 7: T027 ∥ T028.

---

## Implementation Strategy

**MVP = US1 + US2** (Phases 2–4): US1 removes the click-to-dialog path, so the assign flow is only whole again once US2's button exists — deliver them together. Straight-line prior tours (no US4 yet) are acceptable and spec-compliant (`path` fallback), and connections are already road-accurate from the payload.

Then increment: US4 (progressive traces + race hardening — the spec's "essential" robustness) → US3 (dash styling) → Polish.

Stop and validate at every checkpoint with the story's Independent Test.
