# Research: Mandatory Driver Breaks

Building on 013 (`WorkdayEstimator`/`WorkdayEstimate`/`TourSegment`/`PriorTourLeg`, chained day) and
007 (`Tour::total_duration_s = travel_duration_s + Σ stop.duration_s`). Reused slice + decisions.

## Reused slice

- **`WorkdayEstimator::total(warehouse, segments, mode)`** sums the chained day (connection, tour,
  connection, …) counting unknown durations as 0 and flagging `incomplete`. Reusable as-is for the
  with- and without-candidate segment lists.
- **Stop seconds already at hand in the controller**: `priorTourFromAssignment` computes
  `$stops->sum('duration_s')`; the candidate exposes `$candidateTour->stops` (loaded via
  `Tour::with('stops')`). So each tour's stop time is available without a new query.
- **`TravelTimeService` preload/cache** — connections are batched-preloaded then read as cache hits
  in the row closure.
- **Row figure block + `formatDurationHm` + `--primary`** — the emphasis (orange) role already used
  for the candidate path; reused for the break figure.

## Constants

`4h30 = 16200 s`, `45 min = 2700 s`, `30 min = 1800 s`, `6 h = 21600 s`, `9 h = 32400 s`.

## Decisions

### D1 — Driving split lives in the estimator; `WorkdayEstimate` gains `drivingDurationS`

The break needs driving time = total − all stop/service time. The estimator already walks the chain,
so it computes driving in the same pass: connections are all driving; a tour contributes
`durationS − stopSecondsS` to driving. To make the split available, `TourSegment` and `PriorTourLeg`
gain **`stopSecondsS`** (always a known int, independent of routing), and `WorkdayEstimate` gains
**`drivingDurationS`**.

**Unknown-duration handling**: a tour whose travel is unknown counts 0 toward the total *and* its
stop seconds are **not** subtracted (its driving contribution is 0), so driving never goes negative
and stays a consistent best-effort lower bound — mirroring the existing approximate total.

*Alternatives*: compute driving in the controller by re-summing stops — rejected, duplicates the
chain walk and re-derives what the estimator already has. Store driving on the tour — rejected, it
is day-scoped (depends on the chain), not tour-scoped.

### D2 — One pure `MandatoryBreak::secondsFor(int workdayS, int drivingS, bool drivingRuleApplies = true): int`

```
drivingBreak = drivingRuleApplies ? intdiv(drivingS, 16200) * 2700 : 0   // 45 min per 4h30, driven modes only
workdayBreak = workdayS > 32400 ? 2700                  // >9h → 45 min
             : (workdayS > 21600 ? 1800 : 0)            // >6h → 30 min, else 0
return max(drivingBreak, workdayBreak)                  // larger, never the sum
```

Thresholds are strict (`>`), matching "above 6h/9h"; driving uses completed blocks (`intdiv`). The
function is **mutualised** verbatim for the with- and without-candidate days (Constitution II/III —
no duplicated threshold logic). Break is measured on working/driving seconds only, so it never
recurses into the thresholds (FR-006). The driving rule is road-transport only, so
`drivingRuleApplies` is `false` for walked tours (the controller passes `$mode !== Walking`); the
flag (not the mode enum) keeps the calculator decoupled from the delivery-mode type (FR-005a).

*Alternatives*: inline the math twice in the controller — rejected, duplication. Fold into the
estimator — rejected, the break is a policy separate from day totalling (single responsibility).

### D3 — Controller computes the break for both days; `added_break` is the marginal delta

- **With** (existing `segments` = prior + candidate): `estimateWith` →
  `breakWith = secondsFor(withTotal, withDriving)`.
- **Without** (`priorSegments` = prior tours only — the counterfactual "as if we hadn't this tour",
  warehouse → priors → warehouse): `estimateWithout` → `breakWithout`.
- `added_break = breakWith − breakWithout`; `projected_seconds = withTotal + breakWith`.

`added_break ≥ 0` (**monotonic**): the without-day is a strict sub-day of the with-day — same prior
portion, then either a direct `lastPriorEnd → warehouse` return (without) or the longer
`lastPriorEnd → candidate → warehouse` detour plus the candidate's own time (with). Both total and
driving are `≥`, so the step-function break cannot decrease. No prior tours → without-day empty →
`breakWithout = 0`, so `added_break = breakWith`.

**Routing cost**: the without-chain introduces exactly one connection not already preloaded —
`lastPriorEnd → warehouse` — per driver with prior tours (deduped by warehouse/end). The chain
preload is extended to include the without-chain connections, so the row closure stays cache-only.
This is a bounded, batched, and *necessary* addition (the counterfactual cannot be computed without
that leg) — called out explicitly rather than claimed zero-cost.

*Alternatives*: derive `withoutTotal` by subtracting the candidate terms from `withTotal` —
rejected, still needs the `lastPriorEnd → warehouse` duration and re-implements the chain algebra by
hand (error-prone); calling the estimator on `priorSegments` reuses the tested path.

### D4 — Frontend: conditional orange "+"-prefixed figure

`Driver.addedBreak: number` (seconds). `driver-list` renders a "Required break" figure — leftmost of
the right-hand group (before "To tour"), value `+${formatDurationHm(addedBreak)}`, in the `--primary`
(orange) emphasis role — **only when `addedBreak > 0`** (FR-008). `projected_seconds` already
includes the break server-side, so the "Projected workday" figure needs no frontend change beyond
showing the larger number.

*Alternatives*: a separate always-present column — rejected, the spec wants it hidden at 0 and
visually special (it appears only sometimes).
