# Quickstart: Tour Loop Toggle

## Prerequisites

No new env vars. Looping defaults to **on** (closed tour) when a request omits `loop` — the current
behaviour. The TSP `tour` field is sent as `closed`/`open`.

## Run

```bash
php artisan serve
php artisan queue:work --timeout=1320   # >= job_timeout
php artisan reverb:start
npm run dev
```

## Manual verification

1. Open the tour optimize page. **Confirm** the loop toggle sits beside the mode dropdown and reads
   **on** (looped) before any interaction (SC-001).
2. Drop ≥3 stops, leave the toggle on, optimize. Confirm the drawn route includes the segment from the
   last stop back to the first (closed loop).
3. Reset, turn the toggle **off**, optimize the same stops. Confirm:
   - the request carries `loop=false` and the TSP query carries `tour=open` (network tab),
   - the drawn route ends at the last stop with **no** return segment (SC-002),
   - the reported total distance/duration is **less** than the looped case (excludes the return — SC-005),
   - the result is **not** the cached looped tour (shape-keyed cache).
4. While a result is shown, the toggle is not visible (editing-only). Reset → the toggle reappears in its
   last state (retained across reset) → optimize applies it (FR-008).
5. Repeat step 3 for each delivery mode (trucking/driving/walking) — every mode × shape works (SC-004).
6. Force a bad value (curl `loop=maybe`) → expect `422`.

## Tests

```bash
php artisan test --filter "TourOptimization|TourCache|TourGeometry|DeliveryMode"
npm run test -- use-tour-optimization use-tour-geometry loop-toggle mode-select stop-list optimize
```
