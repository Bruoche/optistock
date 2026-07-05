# Contract: Frozen Endpoint I/O (must not change)

This refactor is transparent. Every endpoint's inputs and outputs below are **frozen** — the existing tests assert them and must stay green unchanged. Any deviation is a regression, not a refactor.

## `POST /api/tour/optimize`
- **In**: `{ stops[{lat,lng,duration_s}] (2–10), mode?, loop?, tour_id? }` — same validation (2–10 stops; in-range coords; integer duration; enum mode; boolean loop; owned+unassigned `tour_id`).
- **Out**: unchanged — `200 {status:'done', data:{id, ordered_stops, total_distance_m, total_duration_s}}` (cache hit); `200 {status:'failed', error:{code,message}}` (persist_failed); `202 {status:'pending', job_uuid}` (miss); `422` invalid stops or assigned `tour_id`; `404` foreign/missing `tour_id`.
- **Side effects frozen**: same cache key/TTL, same `OptimizeTourJob` dispatch args, same `TourOptimized` / `TourOptimizationFailed` broadcasts, same DB writes (create OR update-in-place for `tour_id`), same logs.

## `GET /api/tour/status/{job_uuid}`
- **Out**: unchanged — `pending` / `done` / `failed` payloads, or `404 {status:'not_found'}`.

## `GET /tour` and `GET /tour/{tour}/edit` (Inertia)
- **Out**: unchanged — component `tour/optimize`; `editTour = null` on `GET /tour`; on edit, `editTour = {id, mode, loop, stops:[{lat,lng,duration_minutes}] in position order}`; foreign tour → 404; assigned tour → redirect to `tour.optimize.page`.

## The rest of the route-optimization endpoints (now IN scope — refactored, I/O frozen)
- `POST /api/tour/geometry` — same request + same traced-geometry response.
- `GET /api/tour/drivers` — same available-driver rows, byte-identical (`id, name, image_url, modes, warehouse_name, projected_seconds, projected_incomplete, added_break, time_to_tour, time_from_tour, start_index, warehouse_coordinate, previous_tour_end, legs`), same ordering; the availability logic moves controller → `DriverAvailabilityService` + `DriverTourRepository` with no output change.
- `POST /api/tour/{tour}/assign` — same validation + same `{tour_id, driver_id, date, start_index, sequence}` response + same `driver_tour` write; logic moves controller → `TourAssignmentService` + `DriverTourRepository`, idempotent unique-violation behavior preserved.

## Verification
- The whole existing PHP suite (~272 tests) passes with no test logic changed. A test may only be *retargeted* to a moved subject (controller → service/repository) with identical setup/assertions; prefer none.
- The full gate is green (`php artisan test`, plus JS/lint/types/format unaffected since no frontend changes).
