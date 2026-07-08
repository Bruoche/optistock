# Contract: Frozen Endpoint I/O (must NOT change)

This feature is additive. The endpoints below keep their exact inputs, outputs, and side effects; the existing tests assert them and must stay green.

## `POST /api/tour/optimize`
- Unchanged: same request, same `done`/`failed`/`pending` responses, same cache key/TTL, same `OptimizeTourJob` dispatch, same broadcasts, same DB writes, same logs. The force path is a **separate** endpoint — nothing here moves.

## `GET /api/tour/status/{job_uuid}`
- Unchanged.

## `POST /api/tour/geometry`
- Unchanged. Still traces per leg; per-leg failure → `ok:false` straight-segment fallback; totals null unless every leg succeeds. A forced tour reuses this as-is.

## `GET /api/tour/drivers`
- **Output frozen** — same rows, same fields (`id, name, image_url, modes, warehouse_name, projected_seconds, projected_incomplete, added_break, time_to_tour, time_from_tour, start_index, warehouse_coordinate, previous_tour_end, legs`), same ordering, same best-effort semantics (unknown legs → 0 + `projected_incomplete`).
- The robustness audit adds only a **connect timeout** to the `/route` client: a dead host fails faster. Happy-path output, routing-call count, and row values are identical. No field added or removed.

## `POST /api/tour/{tour}/assign`
- Unchanged: same validation, same `{tour_id, driver_id, date, start_index, sequence}` response, same `driver_tour` write, same idempotent unique-violation handling. A forced tour is assigned through this unchanged.

## `GET /tour` and `GET /tour/{tour}/edit` (Inertia)
- Unchanged component + props. (Force is initiated from the failed state of the existing optimize page — no new page/route.)

## Verification
- Full existing PHP + JS suites stay green with **no** test logic changed. New tests are added for the force endpoint and the driver-path API-down robustness. The connect-timeout change is covered by asserting it is applied, with no change to existing driver-row assertions.
