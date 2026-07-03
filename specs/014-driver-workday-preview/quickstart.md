# Quickstart — Driver Workday Preview (014)

## Setup

```bash
composer install && npm install
php artisan migrate:fresh --seed   # seeds warehouses + drivers (013)
composer run dev                   # or: php artisan serve + npm run dev
```

Have at least one driver with a prior assigned tour on the test date (assign one tour first),
plus one driver with none.

## Walkthrough

1. **Build + optimize a tour** (2–10 stops, pick date/mode/loop) → presentation phase shows
   the tour in the highlight color with the driver list.
2. **Button initial state** — an **Assign Driver** button sits right of **New tour**, grayed
   out and non-clickable while no driver is selected.
3. **Click a driver with prior tours** — no pop-up opens. The map now shows their whole
   projected day: dotted neutral line warehouse → first tour, solid neutral line(s) for prior
   tour(s), dotted neutral line into the candidate tour, the candidate tour still in the
   highlight color, dotted neutral line back to the warehouse.
4. **Progressive paths** — prior-tour lines appear instantly as straight segments, then snap
   to road-accurate paths (watch the network tab: one `POST /api/tour/geometry` per
   `geometry: null` leg, **without** `tour_id`). Connection lines are road-accurate
   immediately (their geometry rides the drivers response — no extra `/route` calls).
5. **Rapid cycling** — click quickly through several drivers, faster than traces resolve.
   The map always shows exactly the last-clicked driver's chain; no leftover or mixed
   segments once you stop. Re-selecting a previously viewed driver shows its road paths
   without refetching.
6. **Toggle off** — re-click the selected driver: preview reverts to the candidate tour only,
   button grays out. Changing the date does the same.
7. **Assign** — select a driver, click **Assign Driver** → the familiar confirmation pop-up;
   cancel keeps the preview; confirm records the assignment and returns to the cleared
   creation menu.
8. **Driver with no prior tours** — preview is just warehouse → candidate → warehouse
   (two dotted connections).

## Failure checks

- Kill the routing API key: previews keep straight lines (no blank map, no error state),
  server logs `warning`s, assignment still works.
- `GET /api/tour/drivers` response: every driver has `legs`; connection legs carry
  `geometry`, tour legs carry `geometry: null` + rotated `path`; 013 fields untouched.

## Test suites

```bash
php artisan test     # TravelTimeService geometry cache, WorkdayLegsBuilder, drivers endpoint legs
npm test             # workday-layer, use-workday-preview races, driver-list selection, result-summary button
```
