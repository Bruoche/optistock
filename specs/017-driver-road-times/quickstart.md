# Quickstart — Driver Road-Time Breakdown

Verify the two new per-row road times + the label rename.

## Setup
1. `composer install && npm install`, run the app, log in.
2. Have ≥2 drivers at **different** warehouses, both able to do the tour's mode/day.
3. Add stops, Optimize → result view with the driver list.

## Check
1. Each driver row now shows **three** right-hand figures, left→right:
   **Road to tour** · **Road to warehouse** · **Total projected workday**. ✅ FR-001/002/004/005, SC-001/003.
2. The two new labels are the same muted grey as the old "Projected" label. ✅ FR-003.
3. A driver based farther from the tour shows a larger "Road to tour"/"Road to warehouse"
   than a nearer one. ✅ SC-005.
4. For a driver with **no** prior tour that day and all legs routable:
   Road to tour + tour's own time + Road to warehouse = Total projected workday. ✅ SC-002.
5. For a driver **with** an earlier tour that day, "Road to tour" measures from that tour's
   end (not the warehouse). ✅ FR-001 scenario 2.
6. Force an unroutable bracketing leg (mock/edge) → that figure reads "Unavailable", the
   total stays approximate. ✅ FR-007, SC-004.

## Regression guard (the point of this feature)
- `projected_seconds` for every driver is **identical** to before this change.
- The number of routing (`/route`) calls the drivers endpoint makes is **unchanged**
  (both new values come from already-preloaded connections).

## Automated checks
- Backend (`php artisan test`, `Http::fake`): `DriverAvailabilityTest` extended —
  `time_to_tour`/`time_from_tour` present and equal to the bracketing connection durations;
  `null` when that connection is unroutable; **`projected_seconds` and route-call count
  unchanged** vs before.
- Frontend (`npm run test`): `use-tour-drivers` maps both fields; `driver-list` renders the
  three figures in order, the "Total projected workday" label, and "Unavailable" for a null leg.
- `npm run format:check` · `npm run lint:check` · `npm run types:check` · `./vendor/bin/pint --dirty --test`.
