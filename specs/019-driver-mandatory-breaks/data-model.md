# Data Model: Mandatory Driver Breaks

No database change. New/extended in-memory value objects + one additive API field + a
`projected_seconds` semantic change + the frontend view-model.

## Value objects (backend)

| Object | Change |
|--------|--------|
| `TourSegment` | add `stopSecondsS: int` (stop/service seconds of the tour; always known). |
| `PriorTourLeg` | add `stopSecondsS: int`; `toSegment()` forwards it. |
| `WorkdayEstimate` | add `drivingDurationS: int` (day total minus counted-tour stop seconds). |
| `MandatoryBreak` (**new**) | pure `secondsFor(int workdayS, int drivingS): int`. |

### `MandatoryBreak::secondsFor`

```
drivingBreak = intdiv(drivingS, 16200) * 2700
workdayBreak = workdayS > 32400 ? 2700 : (workdayS > 21600 ? 1800 : 0)
return max(drivingBreak, workdayBreak)
```

Constants: 4h30 = 16200 s, 45 min = 2700 s, 30 min = 1800 s, 6 h = 21600 s, 9 h = 32400 s.

## Estimator driving split

In `chainedDurations`: connections count fully toward driving; a counted (known-duration) tour
contributes `durationS − stopSecondsS`. An unknown-duration tour contributes 0 to both total and
driving, and its stop seconds are not subtracted. `drivingDurationS ≥ 0` always.

## API field / change — `GET /api/tour/drivers` row

| Field | Type | Meaning |
|-------|------|---------|
| `projected_seconds` | `int` | **Changed**: now the working time **plus** the with-candidate mandatory break (`withTotal + breakWith`). |
| `added_break` | `int` (`≥ 0`, seconds) | **New**: the marginal break the candidate adds — `breakWith − breakWithout`. |

## Frontend view-model — `Driver` (types/tour.ts)

```ts
/** Seconds of mandatory rest break this candidate tour adds to the driver's day (feature 019);
 *  0 when the tour crosses no break threshold. Shown as the "+Required break" figure when > 0. */
addedBreak: number;
```

Mapped in `use-tour-drivers.ts`: `addedBreak: driver.added_break`.

## Display — `driver-list.tsx`

| Figure | When | Style |
|--------|------|-------|
| Required break | `addedBreak > 0` | leftmost of the right-hand group; value `+${formatDurationHm(addedBreak)}`; `--primary` (orange) emphasis. |

Order left→right: **Required break** (conditional) · To tour · To warehouse · Projected workday.

## Invariants

- `added_break ≥ 0` (monotonic: without-day ⊆ with-day).
- `added_break === 0` ⟺ the Required break figure is absent.
- `projected_seconds` = working time + `breakWith`; equals the pre-019 value only when `breakWith = 0`.
- Break math depends only on `(workdayS, drivingS)`; the added break never re-enters the thresholds.
- `drivingDurationS ≤ projectedDurationS` (driving is a subset of the day).
