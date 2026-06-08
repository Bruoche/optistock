# Quickstart: Delivery Mode Selection

## Prerequisites

Same as 001/002 — no new env vars. `OPENSTREET_MODE` (default `trucking`) is now only the **fallback**
when a request omits `mode`; the UI sends an explicit mode.

| Var               | Default      | Role                                            |
|-------------------|--------------|-------------------------------------------------|
| `OPENSTREET_MODE` | `trucking`   | Fallback mode when a request omits `mode`.      |

## Run

```bash
# Backend + queue worker + Reverb (as 001/002) and Vite:
php artisan serve
php artisan queue:work --timeout=1320   # >= job_timeout
php artisan reverb:start
npm run dev
```

## Manual verification

1. Open the tour optimize page. **Confirm** the dropdown beneath the map reads **Trucking** and sits to
   the **left** of "Optimize route" (SC-001).
2. Drop ≥3 stops, leave Trucking, click **Optimize route**. Confirm a tour appears and its road path is
   traced.
3. Reset. Switch the dropdown to **Walking**, optimize the **same** stops. Confirm:
   - the request carries `mode=walking` (network tab: `/api/tour/optimize` body),
   - the polyline is traced for walking (`/api/tour/geometry` body has `mode=walking`),
   - the result is **not** the cached trucking tour (mode-keyed cache — SC-002/SC-003).
4. While a tour is shown, change the dropdown to **Driving**. Confirm the shown tour does **not** change
   (FR-008). Reset → Optimize → now driving applies.
5. Force a bad mode (e.g. via curl `mode=flying`) → expect `422` (no silent acceptance).
6. With the `/route` host unreachable for a mode, confirm the straight-line fallback + logged failure
   persist (002 behavior, no foreign-mode path — SC-005).

## Tests

```bash
php artisan test --filter "TourOptimization|TourCache|DeliveryMode|TourGeometry"
npm run test -- use-tour-optimization use-tour-geometry mode-select stop-list optimize
```
