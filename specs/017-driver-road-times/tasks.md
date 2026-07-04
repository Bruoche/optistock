---
description: "Task list for Driver Road-Time Breakdown"
---

# Tasks: Driver Road-Time Breakdown

**Input**: Design documents from `specs/017-driver-road-times/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/driver-road-times.md

**Tests**: Included — constitution requires tests; plan enumerates them.

**Scope**: One backend controller closure (additive fields, no new routing, `projected_seconds`
unchanged) + additive frontend display. Minimal blast radius is the explicit goal.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 = the two road-time figures, US2 = relabel the total

---

## Phase 1: Setup

- [X] T001 Verify baseline green on `017-driver-road-times`: `npm run format:check`, `npm run lint:check`, `npm run types:check`, `npm run test`, `./vendor/bin/pint --dirty --test`, `php artisan test --filter DriverAvailability` all pass before edits.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Emit the two API fields and plumb them to the `Driver` view model. Blocks US1.

**⚠️ CRITICAL**: `projected_seconds` and the endpoint's routing-call count MUST stay unchanged —
the values come from the already-preloaded connection cache, not a new fetch.

- [X] T002 In `app/Http/Controllers/DriverController.php`, add `$travelTime` to the `$driverRows` closure `use(...)`; per row compute `$incoming = $this->incomingPoint($driver, $workday['prior_tours'])`, `time_to_tour = $travelTime->durationBetween($incoming, $workday['start']->start, $mode->value)`, `time_from_tour = $travelTime->durationBetween($workday['start']->end, $warehouse, $mode->value)`, and add `'time_to_tour'`/`'time_from_tour'` to the returned array. Change nothing else (no new preload, no estimator change).
- [X] T003 Extend `tests/Feature/DriverAvailabilityTest.php` (`Http::fake`): assert each row carries `time_to_tour`/`time_from_tour` equal to the bracketing connection durations (with `fakeEveryConnection(60)`, both = 60), and `null` when that connection is unroutable. Do NOT add a redundant call-count assertion — `test_legs_do_not_change_the_thirteen_payload_or_add_route_calls` already locks `assertSentCount(3)` and will catch any new fetch; instead assert in the new test that `projected_seconds` is unchanged for the fixture.
- [X] T004 [P] In `resources/js/types/tour.ts`, add **required** `timeToTour: number | null` and `timeFromTour: number | null` to `Driver`; in `resources/js/hooks/use-tour-drivers.ts`, map `time_to_tour → timeToTour` and `time_from_tour → timeFromTour` in the `ApiDriver` type + the payload `.map(...)`. Because the fields are required (like `legs`), update every full-`Driver` fixture so the types + tests still compile: the `driver()` helper in `resources/js/components/tour/driver-list.test.tsx` and `resources/js/components/tour/result-summary.test.tsx`, the `driver` const in `resources/js/components/tour/assign-driver-dialog.test.tsx`, and the `Driver` object(s) in `resources/js/hooks/use-workday-preview.test.ts` — add `timeToTour`/`timeFromTour` (default `null`, or a number where a test asserts on them). No other mapped field changes.
- [X] T005 [P] Add `resources/js/hooks/use-tour-drivers.test.ts` (new, mock `fetch`): a payload with `time_to_tour`/`time_from_tour` (incl. a `null`) maps onto `Driver.timeToTour`/`timeFromTour`; refetch-on-mode/date/tour behavior unchanged.

**Checkpoint**: API sends the two fields; `Driver` carries them; total untouched.

---

## Phase 3: User Story 1 - Road to tour / Road to warehouse figures (Priority: P1) 🎯 MVP

**Goal**: Each driver row shows the two grey road-time figures.

**Independent Test**: Driver list rows show a "Road to tour" and a "Road to warehouse" time;
an unroutable leg shows "Unavailable".

### Tests for User Story 1

- [X] T006 [US1] Extend `resources/js/components/tour/driver-list.test.tsx`: a row renders "Road to tour" and "Road to warehouse" figures with the driver's `timeToTour`/`timeFromTour` formatted via `formatDurationHm`; a `null` value renders "Unavailable" (not "0 min"); the new labels use the muted label style (same class as the existing total label).

### Implementation for User Story 1

- [X] T007 [US1] In `resources/js/components/tour/driver-list.tsx`, replace the single right-hand "Projected" block with a right-aligned group of three figures, left→right: **Road to tour** (`timeToTour`), **Road to warehouse** (`timeFromTour`), **Total projected workday** (`projectedSeconds`, keeping the `projectedIncomplete` warning icon). Each figure mirrors the existing total's structure — muted uppercase label + default-color value (not a fully-grey figure) — so the row stays visually consistent; format with `formatDurationHm`, rendering `null` as "Unavailable". (This edit also performs the US2 relabel.)

**Checkpoint**: US1 visible; the total is already relabelled by T007.

---

## Phase 4: User Story 2 - Relabel the total (Priority: P2)

**Goal**: The total reads "Total projected workday" and the three figures are correctly ordered.

**Independent Test**: The total figure label reads "Total projected workday" (never "Projected");
figures appear left→right: Road to tour, Road to warehouse, Total projected workday.

**Note**: The relabel + order are delivered by T007 (same `driver-list.tsx` block). This phase
only adds the guarding test — run after US1.

### Tests for User Story 2

- [X] T008 [US2] Extend `resources/js/components/tour/driver-list.test.tsx`: the total figure label reads "Total projected workday" and no label reads "Projected"; assert the three figures render in DOM order Road to tour → Road to warehouse → Total projected workday.

**Checkpoint**: Relabel + ordering guarded.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [X] T009 Run the full gate: `npm run format:check`, `npm run lint:check`, `npm run types:check`, `npm run test`, `./vendor/bin/pint --dirty --test`, `php artisan test --filter DriverAvailability` — all green.
- [X] T010 Run `specs/017-driver-road-times/quickstart.md` manual + regression guard (three figures per row; farther driver → larger road times; no-prior-tour reconciliation with the total; unroutable leg → "Unavailable"; `projected_seconds` + route-call count unchanged).

---

## Dependencies & Execution Order

- **Phase 1**: no deps.
- **Phase 2 (Foundational)**: T002 → T003 (test guards the controller change); T004 → T005; T004 is [P] with T002/T003 (different files). Blocks US1.
- **US1 (Phase 3)**: after Foundational. T006 before/with T007.
- **US2 (Phase 4)**: T008 guards the relabel that T007 already applied → run after US1 (same file).
- **Polish (Phase 5)**: after US1 + US2.

## Parallel Opportunities

- Backend (T002/T003) ∥ frontend plumbing (T004/T005) — different files.
- T006 + T008 are the same test file (`driver-list.test.tsx`) → not parallel with each other; both after T007 conceptually (write T006 first, TDD).

## Implementation Strategy

- **MVP** = Phase 1 + Phase 2 + Phase 3 (US1): API sends the fields, rows show the two road
  times (and the relabelled total, since T007 does both). US2 (Phase 4) then locks the label +
  order with a test.
- Commit after each task/logical group; keep `projected_seconds` + route-call count green throughout.
