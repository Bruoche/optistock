# Quickstart: Manual Tour Duration Fallback

## What ships

1. `POST /api/tour/force` — synchronous write of a tour in current stop order with a manual drive duration.
2. Frontend fallback — on optimization failure, a drive-duration field + **Force Tour** button appear; the forced tour flows into the normal result + driver-assignment view, marked "Manually entered".
3. Driver-assignment back-end audited to never block on an API outage; `/route` client gains a fail-fast connect timeout.

## Try it (API down)

1. Simulate the outage: point `OPENSTREET_API_URL` / `OPENSTREET_ROUTE_URL` at a dead host (or block egress).
2. Place 2–10 stops on the map, press **Optimize route** → it fails (toast); the editing view returns with your stops and now shows the **tour duration (min)** field + **Force Tour** button.
3. Enter a duration (e.g. `90`), press **Force Tour** → the result view opens immediately; the drive duration shows a **Manually entered** badge; distance shows unknown.
4. Pick a date + driver → the driver list loads (best-effort, flagged incomplete where road times are missing) → **Assign Driver** succeeds.

## Verify

**Force endpoint**
```bash
php artisan test --filter=Force
```
- create: stops persisted in input order, `travel_duration_s` = typed×60, `total_distance_m` null, response mirrors optimize `done`.
- edit-in-place (`tour_id`): owned unassigned tour overwritten, not duplicated; vanished target → `persist_failed` (logged).
- validation: missing/0/negative/non-integer/`>86400` duration → 422; foreign `tour_id` → 404; assigned `tour_id` → 422; <2 / >10 stops → 422.
- forced tour is assignable; its saved duration drives the driver workday.

**Driver-path robustness**
```bash
php artisan test --filter=Driver
```
- `GET /api/tour/drivers` with `/route` faked as a connection error → `200`, rows returned, `projected_incomplete:true`, no exception; connect timeout applied.

**Frontend**
```bash
npm test -- tour-control-bar use-tour-optimization result-summary
```
- field + button render only on `failed`; `forceTour` posts + settles `done` with `forced:true`; manual badge shown; invalid/empty duration blocks force.

**Full gate (all must be green — see project memory)**
```bash
php artisan test
npm test
npm run lint
npm run types        # tsc
npm run format:check # prettier — SEPARATE from lint, do not skip
```

## Non-goals / guardrails

- No schema/migration change.
- Optimize / geometry / drivers / assign response shapes unchanged (`contracts/frozen-io.md`).
- Manual value fills only the tour **drive** duration; per-stop durations untouched.
- Field never appears unless an optimization request has errored.
