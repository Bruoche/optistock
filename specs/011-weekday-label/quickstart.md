# Quickstart: Driver Schedule Filtering & Selected-Weekday Label

Manual verification of feature 011 (extends 006 + 010).

## Setup

```bash
php artisan migrate:fresh --seed   # creates week_days + driver_week_day, seeds 7 days + demo drivers with schedules
npm run dev                        # or the containerized stack (feature 008)
```

`DriverDemoSeeder` gives the demo drivers a mix of schedules (Mon–Fri, weekend-only,
a 4-day week, all-week) so filtering visibly changes the list per weekday.

## Verify

1. **Log in** and open the tour optimizer.
2. Place ≥2 stops, pick a **mode**, and **Optimize**.
3. On the **presentation phase** (result view), confirm:
   - a **date field** is shown and defaults to **today**;
   - a **small text beside it** names today's **weekday** (e.g. "Wednesday");
   - the **driver list** shows only drivers who support the mode **and** work today's
     weekday.
4. **Change the date to an upcoming Saturday**:
   - the weekday text updates to **"Saturday"**;
   - the list refreshes to only weekend-scheduled drivers (with the matching mode);
   - if none qualify, **"No one available for this delivery."** appears.
5. **Change the date to a weekday (e.g. Monday)**: the list refreshes to the Mon-set;
   confirm no stale rows from the previous date remain.
6. **New tour → optimize again**: the date field retains the last selected date
   (persists across reset), and the list matches it.

## API spot-check

```bash
# weekday of 2026-07-04 is Saturday; only weekend+driving drivers returned
curl -s --cookie "$COOKIE" \
  'http://localhost/api/tour/drivers?mode=driving&date=2026-07-04' | jq

# missing date → 422
curl -s -o /dev/null -w '%{http_code}\n' --cookie "$COOKIE" \
  'http://localhost/api/tour/drivers?mode=driving'   # → 422
```

## Automated tests

```bash
php artisan test --filter=DriverAvailabilityTest   # mode+weekday filter, date required, weekend vs weekday
php artisan test --filter=WeekDayTest              # fromDate ISO mapping + label↔enum parity
npm run test -- tour-date-field                    # weekday label value/updates, default today
```

## Expected

- The date deduces the weekday **server-side**; a wrong front-end weekday would only
  mislabel the text, never change the returned drivers.
- The label's weekday always matches the weekday used to filter (no disagreement).
- All seven days are selectable; weekend-only schedules work.
