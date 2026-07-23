---
description: "Task list for Driver Management Page"
---

# Tasks: Driver Management Page

**Input**: Design documents from `specs/025-driver-management-page/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/

**Tests**: INCLUDED — the constitution requires tests for new behavior (Principle I) and research.md defines the strategy. PHPUnit (backend feature/unit), Vitest + Testing Library (frontend; jsdom does not evaluate MapLibre paint or media queries — assert on data/props, per existing `workday-layer.test.tsx`).

**Organization**: By user story (US1–US5), in priority order. Each story is an independently testable increment.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallelizable (different file, no incomplete-task dependency)
- **[Story]**: US1–US5; Setup/Foundational/Polish carry no story label
- Paths are repo-relative and exact.

## Reuse note (applies throughout)

Reuse-first per plan.md. As-is imports (do NOT modify): `WorkdayEstimator`, `MandatoryBreak`, `TourStartSelector`, `TravelTimeService`, `WorkdayLeg`/`PriorTourLeg`/`TourSegment`/`WorkdayEstimate`, `DriverTourRepository::{priorToursByDriver,nextSequence,assign}`, `TourMap`, `RouteLayer`, `ActionButton`, `ConfirmDialog`, `ModeSelect`, `TourDateInput`, `types/tour.ts` (`WorkdayLeg`, `formatDurationHm`, `formatWeekday`, `todayDate`). Adapted COPIES (new files): `DayLegsBuilder` (from `WorkdayLegsBuilder`), `DayLayer` (from `workday-layer`), `DayMarkers` (from `workday-markers`), `TourList`/`TourRow` (row styling from `driver-list`/`result-summary`). Frozen I/O: `contracts/frozen-io.md`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Dependencies and folder scaffolding.

- [x] T001 Install drag-reorder deps: `npm i @dnd-kit/core @dnd-kit/sortable @dnd-kit/modifiers` (record in `package.json`; verify `npm run types` still passes)
- [x] T002 [P] Create feature folders: `resources/js/pages/driver/` and `resources/js/components/driver/` (add a temporary `.gitkeep` if empty)

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The page shell every story renders into — reachable at `/driver/{id}` showing its regions with placeholders (SC-002), before any live day data.

**⚠️ CRITICAL**: No user story work begins until this phase is complete.

- [x] T003 Create `app/Http/Controllers/DriverPageController.php` with `manage(Driver $driver)` returning `Inertia::render('driver/manage', ['driverId'=>…, 'initialDate'=>request('date') ?? today, 'warehouses'=>Warehouse::get(['id','name'])])`; unknown driver → `NotFoundHttpException` (mirror `TourPageController::edit`). NO `modes` prop — the mode list comes from the frontend `DELIVERY_MODES` constant (the `DeliveryMode` enum has no labels).
- [x] T004 Register `GET /driver/{driver}` → `DriverPageController@manage` under the `auth,verified` group in `routes/web.php`, name `driver.manage.page`
- [x] T005 [P] Create `resources/js/types/driver.ts` with view models `DayStop`, `DayTour`, `DayWorkday`, `DriverDay` (import `WorkdayLeg`/`DeliveryMode` from `types/tour`; `DriverDay.mode` is `DeliveryMode | null`, null for an empty day) per data-model.md
- [x] T006 Create `resources/js/pages/driver/manage.tsx` page skeleton: identity-bar region, day-bar region, `TourMap` region, tour-list region, with static loading placeholders/spinners and `selectedTourId`/`date` state scaffold — no live day fetch yet (fallbacks visible; SC-002/FR-036)
- [x] T007 [P] Feature test `tests/Feature/DriverPageTest.php`: `/driver/{id}` renders `driver/manage` with `driverId`+`initialDate`+`warehouses` props (no `modes` prop) for an auth user; unknown id → 404; unauthenticated → redirect

**Checkpoint**: `/driver/{id}` loads the framed page with placeholders.

---

## Phase 2.5: Same-day single-mode invariant (existing assignment flow) — FR-045/046

**Purpose**: Guarantee a driver's day is single-mode so the driver page's day `mode` (and every connection/recompute) is unambiguous. Deliberate additive change to the existing available-drivers flow (see contracts/frozen-io.md). Blocks day-mode correctness of every story.

**⚠️ Regression-sensitive**: touches shared assignment behaviour — additive filter only; update existing tests, add new ones.

- [x] T007a [P] Feature test `tests/Feature/AvailableDriversModeTest.php` (new): for a candidate of mode M on date D, a driver with an existing mode-M′ (≠M) assignment on D is EXCLUDED; a driver with no D assignment, or a same-mode-M assignment on D, is INCLUDED; other dates unaffected
- [x] T007b Add the date-aware, mode-only exclusion in `DriverAvailabilityService::rowsFor` (it already has `$date`) by chaining onto the `Driver::available($mode,$weekday)` builder BEFORE `->get()`: `->whereDoesntHave('tours', fn($q)=>$q->wherePivot('date',$date)->whereHas('deliveryMode', fn($m)=>$m->where('label','!=',$mode)))`. Do NOT change the `Driver::available` scope signature (it takes weekday, has no date; its 2-arg contract is pinned by `tests/Unit/DriverTest.php` + the callsite) — this avoids a regression and a duplicate date-aware scope. No other behaviour changes (depends on T007a)
- [x] T007c Confirm no existing available-drivers test regresses: `tests/Unit/DriverTest.php` (scope untouched) and `tests/Feature/DriverAvailabilityRobustnessTest.php` stay green as-is; add setup to any that would now legitimately exclude a driver (expected: none). No assertions loosened

**Checkpoint**: No mixed-mode day can be formed; day `mode` is well-defined.

---

## Phase 3: User Story 1 - See a driver's planned workday for a chosen day (Priority: P1) 🎯 MVP

**Goal**: Load a driver + date and show identity, day workday figures, the neutral day map (tours + connections + warehouse + T-markers), and the ordered tour list with per-tour durations.

**Independent Test**: Open a driver with tours on a date → identity block, four workday figures, map with solid neutral tours + dotted connections + warehouse marker + T1..Tn, and the ordered list with each tour's total/driven/stop durations. Empty day → warehouse-only map + "no tours assigned".

### Tests for User Story 1

- [x] T008 [P] [US1] Unit test `tests/Unit/DayWorkdayServiceTest.php`: totals = driven+stop+break over a day's segments (no candidate); unknown connection → `incomplete=true` and total is a lower bound; empty day → zeros, `incomplete=false`
- [x] T009 [P] [US1] Unit test `tests/Unit/DayLegsBuilderTest.php`: leg order/kinds `connection,tour,connection,…,tour,connection`; k-th tour leg ↔ tours[k]; all legs neutral (`highlight=false`); tour-leg path in driven order (rotated loop / reversed one-way)
- [x] T010 [P] [US1] Feature test `tests/Feature/DriverDayApiTest.php`: `GET /api/driver/{id}/day?date=` returns identity+tours(ordered, stop indexes 1..N)+workday+legs; empty day payload; unknown driver 404; routing-down → 200 with null durations + straight `path` + `incomplete:true` (mock `TravelTimeService`/route client)
- [x] T011 [P] [US1] Frontend test `resources/js/hooks/use-driver-day.test.ts`: loading→ready; a new `date` cancels the prior fetch and discards a late stale response (FR-039); fetch failure → error state (no fabricated data)
- [x] T012 [P] [US1] Frontend test `resources/js/components/driver/day-markers.test.tsx`: renders one warehouse marker + a `T{n}` marker per tour at its `startCoordinate`
- [x] T013 [P] [US1] Frontend test `resources/js/components/driver/tour-list.test.tsx`: renders each tour's total/driven/stop durations in order; empty day → explicit "no tours assigned" message (FR-029)

### Implementation for User Story 1

- [x] T014 [P] [US1] Add `assignmentsForDay(int $driverId, string $date)` read helper to `app/Repositories/DriverTourRepository.php` (ordered rows: tour_id, sequence, stored start/end, loop) — do NOT touch existing methods
- [x] T015 [P] [US1] Create `app/Services/DayLegsBuilder.php` (adapted from `WorkdayLegsBuilder`): all-neutral legs for warehouse→tour1→…→warehouse from stored entry/exit; reuses `TravelTimeService::geometryBetween` and the driven-order stop rotation
- [x] T016 [US1] Create `app/Services/DayWorkdayService.php`: build the day's `TourSegment[]` from `assignmentsForDay`; derive the day `mode` from the tours (first tour's `delivery_mode->label`, null when empty, FR-045); compute totals via `WorkdayEstimator::total` + `MandatoryBreak::secondsFor` (mode-aware, no candidate/counterfactual); preload connections via `TravelTimeService::preload` (single batch) (depends on T014)
- [x] T017 [US1] Create `app/DTOs/DriverDayData.php`: assemble the `GET .../day` payload (identity, derived `mode`, `workday`, ordered `tours` with stops+durations, `legs`) per contracts/driver-day.md (depends on T015, T016)
- [x] T018 [US1] Create `app/Http/Requests/DriverDayRequest.php` (`date` required `Y-m-d`) and add a `day(DriverDayRequest, Driver, DayWorkdayService, DriverTourRepository)` method to `app/Http/Controllers/DriverController.php` returning `{data: DriverDayData}`. **Method-inject** the services (match the existing `available()` style — do NOT add a controller constructor); existing `available()` untouched (depends on T017)
- [x] T019 [US1] Register `GET /api/driver/{driver}/day` → `DriverController@day` with `throttle:tour-read` under `auth` in `routes/api.php`, name `driver.day` (depends on T018)
- [x] T020 [P] [US1] Create `resources/js/hooks/use-driver-day.ts`: fetch `/api/driver/{id}/day?date=`, map wire→view models, cancellation + stale-guard + loading/error fallback state (pattern of `use-tour-drivers`)
- [x] T021 [P] [US1] Create `resources/js/hooks/use-day-geometry.ts`: lazy-trace each leg via frozen `POST /api/tour/geometry` (no `tour_id`), keyed per day-load, straight fallback until traced (copy of `use-workday-preview` pattern)
- [x] T022 [P] [US1] Create `resources/js/components/driver/day-layer.tsx` (adapted from `workday-layer`): draw all legs neutral, tour solid / connection dotted, anchored below `TOUR_ROUTE_LAYER_ID` (highlight comes later in US2)
- [x] T023 [P] [US1] Create `resources/js/components/driver/day-markers.tsx` (adapted from `workday-markers`): warehouse `Building2` marker + `T{n}` marker per tour `startCoordinate`
- [x] T024 [P] [US1] Create `resources/js/components/driver/driver-identity-bar.tsx` (read-only for now): picture/placeholder, name, mode icons (reuse `MODE_ICON` mapping), warehouse name
- [x] T025 [P] [US1] Create `resources/js/components/driver/tour-list.tsx` + `tour-row.tsx`: ordered rows with total/driven/stop durations (`formatDurationHm`), hover-secondary styling, empty-state message; independent-scroll container (FR-028)
- [x] T026 [US1] Create `resources/js/components/driver/day-bar.tsx`: prev/next-day arrows + `TourDateInput` (reuse) + left-side Total/Driven/Stop/Break figures with unavailable + `≥`/warning presentation for `incomplete` (FR-013); Tour-order Update placeholder (disabled)
- [x] T027 [US1] Wire `manage.tsx`: call `use-driver-day` + `use-day-geometry`, render identity-bar/day-bar/`TourMap`(+`DayLayer`+`DayMarkers`)/tour-list; day change re-fetches and clears selection; placeholders while loading (depends on T020–T026)

**Checkpoint**: US1 fully functional — view any driver's day; MVP deliverable.

---

## Phase 4: User Story 2 - Inspect one tour of the day (Priority: P2)

**Goal**: Selecting a tour highlights it (+ bracketing connections) in primary on the map, shows its numbered stops, and unfolds the row to list stops (index/coordinate/duration) matching the markers.

**Independent Test**: With ≥2 tours, click each → map highlight + stop markers move, unfolded indexes match markers; re-click clears; rapid clicks settle on the last.

### Tests for User Story 2

- [x] T028 [P] [US2] Frontend test `resources/js/components/driver/day-layer.test.tsx`: given a `selectedTourIndex`, that tour leg + its two bracketing connection legs get the primary/full-opacity treatment and others dim (assert on computed leg props, not MapLibre paint)
- [x] T029 [P] [US2] Frontend test in `resources/js/components/driver/tour-list.test.tsx` (extend): clicking a row selects (primary class) and unfolds stops with index 1..N + coordinate + duration; re-click clears; hover class is secondary

### Implementation for User Story 2

- [x] T030 [US2] Add `selectedTourId` toggle handling in `manage.tsx` (re-click clears; single selection; cleared on day change) and pass `selectedTourIndex` down
- [x] T031 [US2] Extend `day-layer.tsx`: apply primary highlight + 50% dim driven by `selectedTourIndex` (tour leg + immediately-bracketing connection legs), keyed identity so no stale highlight on rapid change (FR-019/040)
- [x] T032 [P] [US2] Create `resources/js/components/driver/tour-stop-markers.tsx` (or reuse `TourMap` numbered markers): numbered stop markers for the selected tour only; render selected tour path via reused `RouteLayer` (FR-019)
- [x] T033 [US2] Extend `tour-row.tsx`: selected row unfolds a stop sublist (index/coordinate/`formatDurationHm(duration)`); primary-selected vs secondary-hover styling matching the tour pages (FR-024/025)
- [x] T034 [US2] Wire selection into `manage.tsx` map children (stop markers + selected path shown only when a tour is selected; none when cleared, FR-020) (depends on T031–T033)

**Checkpoint**: US1 + US2 work; day view is a full drill-down.

---

## Phase 5: User Story 3 - Correct a driver's details (Priority: P3)

**Goal**: Edit name/picture/modes/warehouse; Update enabled only when dirty; warehouse change warns; save persists and confirms.

**Independent Test**: Update starts disabled; each field change enables it; save persists (reload shows new values); reverting disables; empty name / no mode → 422 with field named; warehouse change → advisory.

### Tests for User Story 3

- [x] T035 [P] [US3] Feature test `tests/Feature/UpdateDriverTest.php`: `PATCH /api/driver/{id}` saves name/modes/warehouse (+optional image on `public` disk); empty name → 422; zero modes → 422; unknown driver → 404; existing assignments untouched
- [x] T036 [P] [US3] Frontend test `resources/js/components/driver/driver-identity-bar.test.tsx`: Update disabled when clean, enabled on any field change, disabled again when reverted; a warehouse change triggers the `ConfirmDialog` advisory before submit; failure keeps edits + Update enabled

### Implementation for User Story 3

- [x] T037 [P] [US3] Create `app/Http/Requests/UpdateDriverRequest.php`: `name` required 1..255, `warehouse_id` required `exists`, `modes[]` required min 1 valid, optional `image` file (rules per contracts/driver-update.md)
- [x] T038 [US3] Create `app/Http/Controllers/DriverUpdateController.php` + `update()`: store optional image (`public` disk), `deliveryModes()->sync`, update row, return `{data:{…saved…}}`; leave assignments intact (depends on T037)
- [x] T039 [US3] Register `PATCH /api/driver/{driver}` → `DriverUpdateController@update` with `throttle:tour-read` under `auth` in `routes/api.php`, name `driver.update` (depends on T038)
- [x] T040 [US3] Make `driver-identity-bar.tsx` editable: name input, image upload, modes multi-select, warehouse select (from `warehouses` prop); dirty-check vs loaded baseline enables the right-aligned `ActionButton` "Update" (FR-006)
- [x] T041 [US3] Add submit flow: warehouse-change `ConfirmDialog` advisory (reuse), multipart PATCH, success resets baseline + disables Update + confirmation, failure keeps edits + shows error (FR-007/008/009); warn on navigate-away while dirty (FR-041) (depends on T040)

**Checkpoint**: US1–US3 independently functional.

---

## Phase 6: User Story 4 - Reorder the day's tours (Priority: P4)

**Goal**: Drag rows to reorder; Tour-order Update enables; save recomputes entry/exit + connections and persists; blocked on unroutable with a Force save; conflict refreshes.

**Independent Test**: Drag last→first → list + T-markers relabel + Update enables; save persists (survives reload); routing-down → blocked + Force save persists degraded; concurrent change → 409 refresh.

### Tests for User Story 4

- [x] T042 [P] [US4] Unit test `tests/Unit/TourOrderServiceTest.php`: recompute picks nearest start per chained incoming point + deduces end; a null connection is DETECTED (not masked by the selector's first-candidate fallback) → normal save reports unroutable; `force` selects lowest-position candidate with ZERO routing calls (assert the route client is never hit); only sequence/start/end change
- [x] T043 [P] [US4] Feature test `tests/Feature/TourOrderTest.php`: `POST /api/driver/{id}/tour-order` 200 persists new sequence+recomputed points and returns fresh day; unroutable → 422 `unroutable_connection`, nothing persisted; `force:true` → 200 degraded; tour-id set mismatch → 409 `assignment_conflict`; unknown driver 404
- [x] T044 [P] [US4] Frontend test (extend `tour-list.test.tsx`): drag reorder updates order + enables Tour-order Update; a 422 response reveals the Force save control; day-change while reordered warns (FR-041)

### Implementation for User Story 4

- [x] T045 [P] [US4] Add `reorder(Driver, string $date, array $orderedRows)` write to `app/Repositories/DriverTourRepository.php`: transactional update of `sequence` + `start/end` per pivot row (existing methods untouched)
- [x] T046 [US4] Create `app/Services/TourOrderService.php` using the day mode (derived from the tours): `preload` the chain's connections once (single batch, Constitution V), chain the new order via `TourStartSelector` (incoming=warehouse→each end), then **measure each chain connection directly and check for null itself** (do not infer routing health from `select()`, which masks failure) → normal path reports unroutable+failed_leg on any null, persists nothing; `force` path is **routing-free** (lowest-position candidate + `endStopForStart`, never calls `select()`); log the degraded force path at `warning` with driver+date (depends on T045)
- [x] T047 [US4] Create `app/Http/Requests/ReorderToursRequest.php` (`date` req, `tour_ids[]` req non-empty ints, `force?` bool) with the set-equality conflict check against current day assignments → 409 (contracts/tour-order.md)
- [x] T048 [US4] Create `app/Http/Controllers/TourOrderController.php` + `reorder()`: 409 conflict / 422 unroutable(+failed_leg) / 200 fresh `DriverDayData`; register `POST /api/driver/{driver}/tour-order` (`throttle:tour-read`, `auth`) in `routes/api.php`, name `driver.tour-order` (depends on T046, T047)
- [x] T049 [US4] Add `@dnd-kit/sortable` drag handles to `tour-row.tsx` (far-left handle; inert on single-tour day) and make `tour-list.tsx` a vertical `SortableContext` with `restrictToVerticalAxis` (FR-030/042)
- [x] T050 [US4] Wire reorder in `manage.tsx`/`day-bar.tsx`: local order state on drag, relabel T-markers, enable Tour-order Update; save → POST; on 422 reveal Force save (re-POST `force:true`); on 200 clear pending + adopt returned day; on 409 refetch; keep list + error on failure (FR-031–034) (depends on T048, T049)

**Checkpoint**: US1–US4 independently functional.

---

## Phase 7: User Story 5 - Fix a tour's contents and come back (Priority: P5)

**Goal**: Row Edit → tour-edit screen carrying return target; successful re-optimize returns to driver+date; back/cancel returns unsaved; failure stays.

**Independent Test**: Edit a tour from a driver day → confirm returns to same driver+date with updated figures; back returns unchanged; a failed optimize stays on the edit screen.

### Tests for User Story 5

- [x] T051 [P] [US5] Feature test `tests/Feature/TourPageControllerTest.php` (extend/new): `tour/{tour}/edit?return_to_driver=&return_to_date=` includes `returnTo` in the `editTour` prop; absent params → prop unchanged (frozen behavior)
- [x] T052 [P] [US5] Frontend test in `resources/js/pages/tour/optimize.test.tsx` (extend): with `editTour.returnTo` set, a successful `done` triggers an Inertia visit to `/driver/{id}?date=…`; a back/cancel action visits it without optimizing; a `failed` state does not navigate (FR-027/a/b)

### Implementation for User Story 5

- [x] T053 [US5] Thread optional `returnTo` (driver id + date) through `app/Http/Controllers/TourPageController.php@edit` and `App\DTOs\EditTourData` into the `editTour` prop (additive; absent = current behavior, frozen-io.md)
- [x] T054 [US5] Add a per-row "Edit" `ActionButton` in `tour-row.tsx` that visits `tour/{tour}/edit` with the return query params (warn if unsaved order/edits, FR-041)
- [x] T055 [US5] In `resources/js/pages/tour/optimize.tsx`: read `editTour.returnTo`; on successful re-optimize navigate back to `/driver/{id}?date=…`; add a back/cancel control that returns unsaved; stay on `failed` (depends on T053)

**Checkpoint**: All stories functional.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [x] T056 [P] Responsive pass 320–2560 px: stack regions, no horizontal overflow, touch-operable select + drag; add `max-md:` variants mirroring 021/022 (FR-042/SC-008)
- [x] T057 [P] Palette audit: every new color via role variables (`--primary`/`--secondary`/`--route-neutral`/`--accent`/`text-on-color`); no off-palette literals (Constitution VI/FR-043)
- [x] T058 Verify no silent failures: every failed load/save shows a message; degraded routing logged server-side (Constitution IV / SC-010)
- [x] T059 Run full CI gate `composer ci:check` (= `npm run lint:check` + `npm run format:check` + `npm run types:check` + `composer test`) plus `npm run test` (Vitest) — all green. Use `lint:check`/`types:check` (NOT `npm run lint`, which auto-fixes; NOT `npm run types`, which does not exist); Prettier `format:check` is separate from ESLint — do not skip
- [x] T060 Run `quickstart.md` walkthrough end-to-end; confirm frozen optimize/geometry/drivers/assign tests still pass unchanged

---

## Dependencies & Execution Order

### Phase dependencies

- **Setup (P1)**: no deps.
- **Foundational (P2)**: after Setup — BLOCKS all stories.
- **Phase 2.5 (FR-045/046)**: after Setup; independent of the page work (touches the existing assignment flow). BLOCKS day-mode correctness — should land before US1's day payload is trusted, but its tasks (T007a–c) can run in parallel with T003–T007.
- **US1 (P3)**: after Foundational + Phase 2.5. The MVP; other stories build on its page + day data.
- **US2 (P4)**: after US1 (extends the map + tour list with selection).
- **US3 (P5)**: after Foundational (needs the identity bar T024/page); largely independent of US1 data — can parallel US1 once T024 exists.
- **US4 (P6)**: after US1 (reorders US1's tour list/day) + US2 optional (T-marker relabel is clearer with selection).
- **US5 (P7)**: after US1 (Edit lives on the tour row); backend T053 independent.
- **Polish (P8)**: after the desired stories.

### Within a story

- Tests first (write, watch fail) → repository/service → DTO/request → controller → route → frontend components → page wiring.
- Backend and frontend `[P]` tasks of a story touch different files and can parallelize.

### Parallel opportunities

- Setup: T002 ∥ T001.
- Foundational: T005, T007 ∥ (T003→T004), T006 after T005.
- US1 tests T008–T013 all ∥. US1 impl: {T014, T015} ∥; T016 after T014; then T017→T018→T019; frontend T020–T025 ∥, T026 after, T027 last.
- US3 backend (T037→T038→T039) ∥ US1 frontend once Foundational done.
- Cross-team: after Foundational, US1 and US3 can run in parallel; US2/US4/US5 join once US1 lands.

---

## Parallel Example: User Story 1 tests

```bash
Task: "Unit test DayWorkdayServiceTest.php"      # T008
Task: "Unit test DayLegsBuilderTest.php"          # T009
Task: "Feature test DriverDayApiTest.php"         # T010
Task: "Frontend test use-driver-day.test.ts"      # T011
Task: "Frontend test day-markers.test.tsx"        # T012
Task: "Frontend test tour-list.test.tsx"          # T013
```

---

## Implementation Strategy

### MVP (US1 only)

1. Setup → Foundational → US1.
2. STOP & VALIDATE: view any driver's day (map + figures + list), empty-day + routing-down fallbacks.
3. Deploy/demo.

### Incremental

US1 (view) → US2 (inspect) → US3 (edit driver) → US4 (reorder) → US5 (edit round-trip). Each ships without breaking the prior.

---

## Notes

- `[P]` = different files, no incomplete-task dependency.
- Do NOT modify frozen files (contracts/frozen-io.md). Two deliberate touches to existing code: Phase 2.5's FR-046 filter on the available-drivers flow, and US5's `returnTo` on the tour-edit page — both additive and tested.
- jsdom evaluates no MapLibre paint / media queries — assert map behavior at the data/prop layer.
- Commit after each task or logical group; stop at any checkpoint to validate a story independently.
