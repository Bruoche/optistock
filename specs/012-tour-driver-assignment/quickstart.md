# Quickstart: Tour Driver Assignment (+ persistence)

Manual verification of feature 012 (extends 001/002/006/007/011).

## Setup

```bash
php artisan migrate:fresh --seed   # adds tours, stops, driver_tour; seeds drivers + schedules
php artisan queue:work             # the optimize job (Reverb + queue running)
npm run dev
```

## Verify — persistence on optimize

1. Log in, place ≥2 stops (give some non-default durations), pick a mode, Optimize.
2. When the result appears, confirm a `tours` row + its `stops` exist:
   ```bash
   php artisan tinker --execute="echo App\Models\Tour::latest('id')->first()->stops()->count();"
   ```
   The stop count matches, `position` reflects the optimized order, and each
   `duration_s` matches what you entered.
3. Confirm the tour's `travel_duration_s` becomes the road value after the map traces.
   **2-point tours are traced too** — they get a road value; `travel_duration_s` stays
   **null (unknown)** only when no routing call/trace succeeds, never silently the estimate.

## Verify — assignment + projected hours

4. On the presentation phase, each driver row shows **projected hours** = their
   committed hours for the date + this tour's duration. For a fresh driver it equals
   this tour's duration.
5. Click a driver → a confirmation dialog names them. **Cancel** → nothing happens, list
   intact.
6. Click again → **Confirm** → you land back on the **cleared** route creation menu.
7. Confirm the assignment persisted:
   ```bash
   php artisan tinker --execute="echo App\Models\Tour::latest('id')->first()->drivers()->count();"
   ```
8. Plan + optimize a **second** tour for the **same date**, and confirm the driver you
   assigned in step 6 now shows higher projected hours (their first tour + the new one).
9. Change the date on the bar to a different weekday → the list re-filters and the
   projected hours reflect that date's assignments.

## API spot-checks

```bash
# assign (Monday 2026-07-06); ineligible driver → 422
curl -s -X POST --cookie "$COOKIE" -H 'Content-Type: application/json' \
  -d '{"driver_id":12,"date":"2026-07-06"}' http://localhost/api/tour/42/assign | jq

# drivers now carry assigned_seconds
curl -s --cookie "$COOKIE" 'http://localhost/api/tour/drivers?mode=driving&date=2026-07-06' | jq '.data[0]'
```

## Automated tests

```bash
php artisan test --filter="TourPersistenceTest|TourAssignmentTest|DriverAvailabilityTest|TourTest"
npm run test -- assign-driver-dialog driver-list
```

## Verify — persist failure is surfaced (FR-014)

10. Simulate a save error (e.g. temporarily rename the `stops` table, or throw inside
    `TourRecorder::record`) and Optimize. Expect a **toast** ("route could not be saved")
    and **no** driver list — the route never reaches the assignable presentation phase, and
    a `persist_failed` entry is logged. Restore, retry → the cached result saves without a
    second upstream call.

## Expected / regression watch

- One `tours` row per optimization on **both** the cache-hit and job paths; the
  broadcast+poll dual-settle never duplicates it.
- Optimization (TSP) failure paths still surface (toast) and never hang.
- A **persist** failure is logged and surfaced as `persist_failed` (both paths); no unsaved
  route is shown as assignable, and the TSP result stays cached for retry (FR-014).
- Assignment re-validates ownership + eligibility server-side; a failed assign toasts and
  keeps you on the presentation phase with nothing recorded; a concurrent double-assign is
  idempotent, not a 500.
