# Implementation Plan: Mandatory Driver Breaks

**Branch**: `019-driver-mandatory-breaks` | **Date**: 2026-07-04 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/019-driver-mandatory-breaks/spec.md`, building on 013
(`WorkdayEstimator`, `WorkdayEstimate`, `TourSegment`, chained day), 007 (per-stop durations →
`Tour::total_duration_s = travel_duration_s + Σ stop.duration_s`), 017/018 (drivers-row fields).

## Summary

Fold legally-mandated rest breaks into each driver's **Projected workday** and surface the
**marginal** break the candidate tour adds. Break per day = `max(workdayBreak, drivingBreak)` where
`workdayBreak` is 0/30/45 min by the 6 h / 9 h total-day thresholds and `drivingBreak` is
`floor(driving / 4h30) × 45 min`. Driving time = the day's total minus all stop/service time.

`WorkdayEstimator` gains an **extra step**: alongside the total it now returns **driving seconds**
(total − Σ stop seconds of counted tours). A new pure `MandatoryBreak::secondsFor(workdayS,
drivingS)` computes the break — **mutualised**, called twice per driver: once for the day **with**
the candidate, once **without** it (the counterfactual day: warehouse → prior tours → warehouse).
The row gains `added_break = breakWith − breakWithout`, and `projected_seconds` now **includes**
`breakWith`. Frontend shows a conditional orange, "+"-prefixed **Required break** figure when
`addedBreak > 0`, left of the existing figures.

## Technical Context

**Stack**: Laravel 12 (PHP) + React 19 + Inertia + Tailwind v4 + shadcn/ui; PHPUnit (`Http::fake`) +
Vitest/Testing Library.

**Existing pieces reused**:
- `WorkdayEstimator::total` / `WorkdayEstimate` (013) — extended to also carry driving seconds.
- `TourSegment` / `PriorTourLeg` — gain `stopSecondsS` (always-known int) so the estimator can
  split driving from stop time; stop sums already computed in the controller (`$stops->sum
  ('duration_s')`, `$candidateTour->stops`).
- `TravelTimeService` preload/cache — the counterfactual day needs one new connection
  (`lastPriorEnd → warehouse`) per prior-tour driver; preloaded in batch (no per-row network).
- `formatDurationHm` + the row figure block (018) — reused; the break figure uses the `--primary`
  emphasis role.

**Project Type**: web app (Laravel + React SPA).

**Performance/Constraints**: the counterfactual (without-candidate) day is inherent to the marginal
break, and its `lastPriorEnd → warehouse` connection is a **genuine but bounded new routing class**
(only for drivers with prior tours, deduped by shared warehouse/end, preloaded in one batch). All
other values reuse the already-preloaded cache. Break math is integer arithmetic, no network.

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — `MandatoryBreakTest` (boundary table: 6 h/9 h/4 h30 exact & ±,
  max-not-sum, non-recursive); `WorkdayEstimator` driving-split (stops subtracted, unknown travel
  counts 0 both sides); `DriverAvailabilityTest` (`added_break` = with−without, hidden-as-0 case,
  `projected_seconds` now includes `breakWith`, prior-tour and no-prior cases); `use-tour-drivers`
  + `driver-list` (maps + renders the conditional orange "+"-figure). PASS.
- **II/III. Readable & Simple** — one pure break function reused for both days (no duplicated
  threshold logic); the driving split is one added pass in the existing estimator; the controller
  calls the estimator twice with the two segment lists. Single responsibility each. PASS.
- **IV. Robustness** — break derived from best-effort known seconds; when travel is unknown the
  existing `projected_incomplete` flag stays and the break is a lower bound; `added_break` is
  clamped to `≥ 0` (`max(0, …)`) so an unroutable candidate leg can never surface a negative
  "gained" break, even though the without-day is otherwise a strict sub-day of the with-day. PASS.
- **V. Performance with Clarity** — integer math; one extra preloaded connection class for the
  counterfactual, batched; no new per-row network. The added routing is justified and documented
  (it is the counterfactual the feature requires). PASS.
- **VI. Consistent, Reusable Styling** — the break figure reuses the row figure block and the
  `--primary` emphasis role (orange); no raw literal, no new palette entry. PASS.

No violations. (Complexity Tracking omitted.)

## Decisions

Full rationale + alternatives in [research.md](research.md); condensed:

- **D1 — `WorkdayEstimate` gains `drivingDurationS`; the estimator computes it in the same chained
  pass** as `total − Σ stop seconds of counted tours`. `TourSegment`/`PriorTourLeg` gain
  `stopSecondsS`. Unknown-duration tours contribute 0 to both total and driving (their stops are
  not subtracted), keeping the split consistent with the existing approximate behavior. (research D1)
- **D2 — New pure `MandatoryBreak::secondsFor(int workdayS, int drivingS): int`** —
  `drivingBreak = intdiv(drivingS, 16200) × 2700`; `workdayBreak = workdayS > 32400 ? 2700 :
  (workdayS > 21600 ? 1800 : 0)`; `return max(...)`. One function, called for both days
  (mutualised). (research D2)
- **D3 — Controller computes the break twice**: `withSegments` (prior + candidate, existing) and
  `priorSegments` (prior only, the counterfactual). `added_break = max(0, breakWith − breakWithout)`
  (clamped — unroutable candidate legs can make the raw delta negative); `projected_seconds =
  withTotal + breakWith`. Preload extends by the without-chain connections (adds only
  `lastPriorEnd → warehouse`). (research D3)
- **D4 — Frontend `addedBreak: number`**; `driver-list` renders a "Required break" figure (orange
  `--primary`, "+"-prefixed, `formatDurationHm`) as the leftmost of the right-hand group, only when
  `addedBreak > 0`. (research D4)

## Project Structure (feature-specific)

Backend — **change**:
- `app/Services/MandatoryBreak.php` — **new**: pure `secondsFor(int workdayS, int drivingS): int`.
- `app/Services/TourSegment.php` — add `stopSecondsS: int`.
- `app/Services/PriorTourLeg.php` — add `stopSecondsS: int`; `toSegment()` passes it through.
- `app/Services/WorkdayEstimate.php` — add `drivingDurationS: int`.
- `app/Services/WorkdayEstimator.php` — track driving (counted travel) in `chainedDurations`; return
  it on the estimate.
- `app/Http/Controllers/DriverController.php` — populate `stopSecondsS` on the candidate segment
  (`$candidateTour->stops->sum('duration_s')`) and prior legs (already summed at
  `priorTourFromAssignment`); build `priorSegments`; compute `breakWith`/`breakWithout` via
  `MandatoryBreak`; return `projected_seconds = withTotal + breakWith` and `added_break`; extend the
  chain preload with the without-chain connections.

Frontend — **change**:
- `resources/js/types/tour.ts` — `Driver` gains `addedBreak: number`.
- `resources/js/hooks/use-tour-drivers.ts` — map `added_break → addedBreak`.
- `resources/js/components/tour/driver-list.tsx` — render the conditional "Required break" figure
  (orange, "+"-prefixed) left of "To tour"; hidden when `addedBreak === 0`.

Tests: `tests/Unit/MandatoryBreakTest.php` (**new**), `tests/Unit/WorkdayEstimatorTest.php` (extend
or new — driving split), `tests/Feature/DriverAvailabilityTest.php` (extend), `use-tour-drivers
.test.ts` (extend — mapping), `driver-list.test.tsx` (extend — figure shown/hidden/order/orange).

Out of scope: changing driver ordering/selection/preview/assignment; per-tour break breakdown;
persisting breaks; any legal rule beyond the three thresholds stated.

## Flow

1. Per driver, the estimator returns `(projectedDurationS, drivingDurationS, incomplete)` for the
   **with-candidate** segments and for the **prior-only** segments.
2. `MandatoryBreak::secondsFor` turns each `(total, driving)` into a break; `added_break =
   breakWith − breakWithout`; `projected_seconds = withTotal + breakWith`.
3. `useTourDrivers` maps `added_break → addedBreak`.
4. `DriverList` shows the orange "+Required break" figure when `addedBreak > 0`, leftmost.

## API contracts (this run)

- `GET /api/tour/drivers?mode&date&tour` — `projected_seconds` now **includes** the mandatory break;
  response gains `added_break` (`int ≥ 0`, seconds). See `contracts/driver-mandatory-breaks.md`.

## Design Artifacts (this run)

- `research.md` — decisions D1–D4 (break formula, driving split, counterfactual, monotonicity).
- `data-model.md` — new/extended value objects, API field, view-model, invariants.
- `contracts/driver-mandatory-breaks.md` — `added_break` + the `projected_seconds` semantic change.
- `quickstart.md` — boundary + marginal verification incl. the added-routing note.

---

Generated by speckit.plan on 2026-07-04
