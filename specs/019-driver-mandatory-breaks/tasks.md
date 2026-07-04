---
description: "Task list for Mandatory Driver Breaks"
---

# Tasks: Mandatory Driver Breaks

**Input**: Design documents from `specs/019-driver-mandatory-breaks/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/driver-mandatory-breaks.md

**Tests**: Included — constitution requires tests; plan enumerates them.

**Scope**: A pure break calculator + a driving-time split on the estimator (shared), then the
controller folds `breakWith` into `projected_seconds` (US1) and emits the marginal `added_break`
that drives a conditional orange figure (US2).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 = breaks in Projected workday, US2 = the "Required break" figure

---

## Phase 1: Setup

- [ ] T001 Verify baseline green on `019-driver-mandatory-breaks`: `npm run format:check`, `npm run lint:check`, `npm run types:check`, `npm run test`, `./vendor/bin/pint --dirty --test`, `php artisan test` all pass before edits.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The break calculator + the estimator's driving split — both user stories depend on them.

**Constants**: 4h30 = 16200 s, 45 min = 2700 s, 30 min = 1800 s, 6 h = 21600 s, 9 h = 32400 s.

- [ ] T002 [P] Create `app/Services/MandatoryBreak.php`: pure `public static function secondsFor(int $workdayS, int $drivingS): int` = `$drivingBreak = intdiv($drivingS, 16200) * 2700`; `$workdayBreak = $workdayS > 32400 ? 2700 : ($workdayS > 21600 ? 1800 : 0)`; `return max($drivingBreak, $workdayBreak);`. No dependencies, no state.
- [ ] T003 [P] Create `tests/Unit/MandatoryBreakTest.php`: boundary table — `secondsFor(21600,0)=0`, `secondsFor(21601,0)=1800`, `secondsFor(32400,0)=1800`, `secondsFor(32401,0)=2700`; driving `secondsFor(0,16199)=0`, `secondsFor(0,16200)=2700`, `secondsFor(0,32400)=5400`; max-not-sum `secondsFor(32401,32400)=5400`.
- [ ] T004 Add the driving split to the estimator: in `app/Services/TourSegment.php` add `public readonly int $stopSecondsS`; in `app/Services/PriorTourLeg.php` add `public readonly int $stopSecondsS` and pass it through `toSegment()`; in `app/Services/WorkdayEstimate.php` add `public readonly int $drivingDurationS`; in `app/Services/WorkdayEstimator.php` compute driving in the `chainedDurations`/`total` pass — connections count fully, a known-duration tour contributes `durationS − stopSecondsS`, an unknown-duration tour contributes 0 (its stops are NOT subtracted) — and return it on `WorkdayEstimate` (`drivingDurationS ≥ 0`). Because `stopSecondsS` is required, update EVERY existing construction site so the code compiles: `PriorTourLeg::toSegment` (forward it), `DriverController` (done in US1/US2), and the test fixtures in T005.
- [ ] T005 Extend `tests/Unit/WorkdayEstimatorTest.php`: `drivingDurationS` = total − stop seconds for a known day; a tour with unknown travel adds 0 to both total and driving (stops not subtracted); `drivingDurationS ≥ 0` and `≤ projectedDurationS`. Add `stopSecondsS` to the 6 `new TourSegment(...)` fixtures in this file. **Also** add `stopSecondsS: 0` to the `new PriorTourLeg(...)` fixture in `tests/Unit/WorkdayLegsBuilderTest.php:64` (stop seconds are irrelevant to that leg-geometry test) so it still compiles.

**Checkpoint**: `MandatoryBreak` computes breaks; the estimator returns driving seconds.

---

## Phase 3: User Story 1 - Breaks folded into Projected workday (Priority: P1) 🎯 MVP

**Goal**: `projected_seconds` includes the with-candidate mandatory break.

**Independent Test**: A driver whose projected day crosses a threshold shows a Projected workday
larger than the raw working time by exactly the break; a sub-threshold day is unchanged.

### Implementation for User Story 1

- [ ] T006 [US1] In `app/Http/Controllers/DriverController.php`, set `stopSecondsS` when building segments: on `$candidateSegment` use `(int) $candidateTour->stops->sum('duration_s')`; on prior legs pass `(int) $stops->sum('duration_s')` in `priorTourFromAssignment`. In the `$driverRows` closure compute `$breakWith = MandatoryBreak::secondsFor($estimate->projectedDurationS, $estimate->drivingDurationS)` and return `'projected_seconds' => $estimate->projectedDurationS + $breakWith` (keep `projected_incomplete` as-is). Do not add `added_break` yet.

### Tests for User Story 1

- [ ] T007 [US1] Extend `tests/Feature/DriverAvailabilityTest.php`: a day crossing 6 h (≤ 9 h) reports `projected_seconds` = working time + 1800; a day over 9 h + 2700; a day with driving over 4 h 30 (short total) + 2700 from the driving rule; a sub-threshold day is unchanged (the existing 720/1380 fixtures still hold — assert one explicitly). Use `fakeEveryConnection` with large enough values / stop durations to cross the boundaries.

**Checkpoint**: Projected workday reflects mandatory breaks.

---

## Phase 4: User Story 2 - "Required break" figure (Priority: P2)

**Goal**: The row surfaces the marginal break the candidate adds, in orange with "+", hidden at 0.

**Independent Test**: A candidate that raises the driver's day break shows "+Xmin" in orange
leftmost; a candidate crossing no threshold shows no figure.

### Implementation for User Story 2

- [ ] T008 [US2] In `app/Http/Controllers/DriverController.php`, build `priorSegments` (the prior tours only, `$priorTours->map(fn (PriorTourLeg $t) => $t->toSegment())`), compute `$estimateWithout = $workdayEstimator->total($warehouse, $priorSegments, $mode->value)` and `$breakWithout = MandatoryBreak::secondsFor($estimateWithout->projectedDurationS, $estimateWithout->drivingDurationS)`; return `'added_break' => max(0, $breakWith - $breakWithout)` (clamped: unroutable candidate legs can make the raw delta negative — the "amount gained" is never below 0). Extend the `$chainConnections` preload to also include `connectionsAlongChain($warehouse, $priorSegments)` per workday so the without-chain's `lastPriorEnd → warehouse` connection is a cache hit (no per-row fetch).
- [ ] T009 [US2] Extend `tests/Feature/DriverAvailabilityTest.php`: `added_break` = `max(0, breakWith − breakWithout)`; `0` when the candidate crosses no threshold; equals `breakWith` for a driver with no prior tour; the "+15 min" case (prior tours already require 30 min, candidate raises the day to 45 min → `added_break = 900`); and a case where the candidate's bracketing connections are unroutable while a prior tour's return is routable → `added_break` clamps to `0` (never negative). Account for the added `lastPriorEnd → warehouse` preload in any `Http::assertSentCount` expectations.
- [ ] T010 [P] [US2] In `resources/js/types/tour.ts` add `addedBreak: number` to `Driver`; in `resources/js/hooks/use-tour-drivers.ts` add `added_break` to `ApiDriver` and map `added_break → addedBreak`. Update every full-`Driver` fixture (add `addedBreak: 0`): `driver()` in `resources/js/components/tour/driver-list.test.tsx` and `resources/js/components/tour/result-summary.test.tsx`, the `driver` const in `resources/js/components/tour/assign-driver-dialog.test.tsx`, the `Driver` in `resources/js/hooks/use-workday-preview.test.ts`, and the `apiDriver` helper in `resources/js/hooks/use-tour-drivers.test.ts` (add `added_break: 0`).
- [ ] T011 [US2] In `resources/js/components/tour/driver-list.tsx`, render a "Required break" figure as the leftmost child of the right-hand figure group, only when `driver.addedBreak > 0`: the **value** `+${formatDurationHm(driver.addedBreak)}` in the `--primary` (orange) emphasis role (`text-primary`); the label ("Required break") stays the muted uppercase style of the other figures' labels (only the value is orange, so the figure reads as distinctive without a second color). No change to the other figures.

### Tests for User Story 2

- [ ] T012 [P] [US2] Extend `resources/js/hooks/use-tour-drivers.test.ts`: a payload with `added_break` maps onto `Driver.addedBreak` (incl. a non-zero value).
- [ ] T013 [US2] Extend `resources/js/components/tour/driver-list.test.tsx`: a driver with `addedBreak > 0` renders a "Required break" figure reading `+…` in the orange role, positioned left of "To tour"; a driver with `addedBreak === 0` renders no such figure.

**Checkpoint**: The conditional orange "+Required break" figure works and is gated on `addedBreak`.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [ ] T014 Run the full gate: `npm run format:check`, `npm run lint:check`, `npm run types:check`, `npm run test`, `./vendor/bin/pint --dirty --test`, `php artisan test` — all green.
- [ ] T015 Run `specs/019-driver-mandatory-breaks/quickstart.md` manual checks: short day (no figure, unchanged total); crosses 6 h (+30) / 9 h (+45); long driving (+45); prior-at-30 candidate → "+15 min"; orange, leftmost, hidden at 0.

---

## Dependencies & Execution Order

- **Phase 1**: no deps.
- **Phase 2 (Foundational)**: T002 → T003; T004 → T005; T002 ∥ T004 (different files). Blocks US1/US2.
- **US1 (Phase 3)**: after Foundational. T006 (needs T002 + T004) → T007.
- **US2 (Phase 4)**: after US1 — T008 extends the same closure T006 edits. T008 → T009; T010 → T012; T011 → T013; T010 before T011 (types). Backend (T008/T009) ∥ frontend (T010–T013) except the shared ordering noted.
- **Polish (Phase 5)**: after US1 + US2.

## Parallel Opportunities

- T002 ∥ T004 (calculator vs estimator, different files); their tests T003/T005 follow.
- In US2: backend (T008/T009) ∥ frontend plumbing (T010/T012); T011/T013 follow T010.

## Implementation Strategy

- **MVP** = Phase 1 + Phase 2 + Phase 3 (US1): breaks are correctly folded into Projected workday.
  US2 (Phase 4) then adds the marginal "Required break" figure.
- Commit after each task/logical group; keep the suite green throughout.
