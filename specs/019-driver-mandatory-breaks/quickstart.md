# Quickstart: Mandatory Driver Breaks

## Verify (manual)

1. Optimize a tour, reach the presentation view.
2. **Short day** (driver whose whole projected day ≤ 6 h and driving ≤ 4 h 30): Projected workday
   equals the raw working time; **no "Required break"** figure.
3. **Crosses 6 h** (day just over 6 h, ≤ 9 h, driving under 4 h 30): Projected workday grows by
   30 min; if this candidate is what crosses 6 h, a **"+30 min"** orange Required break shows,
   leftmost of the figures.
4. **Crosses 9 h**: the workday break is 45 min instead of 30.
5. **Long driving** (over 4 h 30 driving but short total day, e.g. long single drive): Projected
   workday grows by 45 min from the driving rule even though the workday rule alone would add none.
6. **Prior tour already needs a break**: pick a driver whose prior tours already require 30 min and
   a candidate that raises the day to 45 min → Required break reads **"+15 min"** (the increase).

## Verify (automated)

- **Unit** `tests/Unit/MandatoryBreakTest.php` (new): boundary table —
  - `secondsFor(21600, 0) = 0`, `secondsFor(21601, 0) = 1800`, `secondsFor(32400, 0) = 1800`,
    `secondsFor(32401, 0) = 2700`.
  - driving: `secondsFor(0, 16199) = 0`, `secondsFor(0, 16200) = 2700`, `secondsFor(0, 32400) = 5400`.
  - max not sum: `secondsFor(32401, 32400) = 5400` (driving 5400 > workday 2700).
- **Unit** `WorkdayEstimator` driving split: driving = total − stop seconds; an unknown-travel tour
  adds 0 to both total and driving (stops not subtracted); `drivingDurationS ≥ 0`.
- **Feature** `tests/Feature/DriverAvailabilityTest.php` (extend):
  - `projected_seconds` includes `breakWith` (a day crossing 6 h is 30 min larger than the raw sum).
  - `added_break` = `breakWith − breakWithout`; `0` when the candidate crosses no threshold; equals
    `breakWith` for a driver with no prior tour; `+15 min` case for a driver already at 30 min.
- **Frontend**:
  - `use-tour-drivers.test.ts` — maps `added_break → addedBreak`.
  - `driver-list.test.tsx` — the "Required break" figure shows `+…` in the orange role, leftmost,
    only when `addedBreak > 0`; hidden at 0.

## Note — routing calls

Unlike 017/018 this adds one connection class (`lastPriorEnd → warehouse`) for the counterfactual
day, per driver with prior tours, preloaded in batch. Expect the `Http::fake` count to include these
in tests that use prior tours; drivers with no prior tour add none.

## Full CI gate (run before "done")

```
npm run format:check
npm run lint:check
npm run types:check
npm run test
./vendor/bin/pint --dirty --test
php artisan test
```
