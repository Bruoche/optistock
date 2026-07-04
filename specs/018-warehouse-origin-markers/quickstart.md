# Quickstart: Warehouse & Origin Map Markers

## Verify (manual)

1. Optimize a tour, reach the presentation view.
2. **Select a driver with no assigned tour that day**: a building-icon marker appears at that
   driver's warehouse (same size as the numbered stop markers, near-black at 50% opacity); **no
   "0" marker** is shown.
3. **Select a driver who already has an assigned tour that day**: the warehouse marker still shows,
   **plus a "0" marker** at the end of that prior tour (where the driver drives in from), same
   styling.
4. Deselect / select another driver: markers clear or move to the new driver's warehouse/origin.
5. Toggle the theme (light/dark): both markers stay legible; the building glyph and "0" read on the
   neutral fill in both themes.

## Verify (automated)

- **Backend** — `tests/Feature/DriverAvailabilityTest.php` (extend):
  - `warehouse_coordinate` equals the driver's warehouse `[lat,lng]`.
  - `previous_tour_end` is `null` for a driver with no prior tour that day.
  - `previous_tour_end` equals the last prior tour's end `[lat,lng]` when the driver has one.
  - `projected_seconds` and the OSRM route-call count are **unchanged** from the pre-018 expectation
    (assert the `Http::fake` call count did not grow).
- **Frontend**:
  - `use-tour-drivers.test.ts` — maps `warehouse_coordinate` → `warehouseCoordinate` and
    `previous_tour_end` → `previousTourEnd` (including the `null` case).
  - `workday-markers.test.tsx` (new) — renders the warehouse marker for a selected driver; renders
    the "0" marker only when `previousTourEnd` is non-null; both carry the neutral-50% styling.

## Full CI gate (run before "done")

```
npm run format:check     # prettier (separate from eslint — do not skip)
npm run lint:check       # eslint
npm run types:check      # tsc
npm run test             # vitest
./vendor/bin/pint --dirty --test   # PHP style
php artisan test --filter DriverAvailability   # backend feature test
```
