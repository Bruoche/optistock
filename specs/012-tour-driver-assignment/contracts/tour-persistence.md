# Contract: Tour Persistence (optimize / geometry / drivers changes)

Extends the feature-001/002/006/011 endpoints. Auth + throttling unchanged.

## `POST /api/tour/optimize` (request shape change)

The request now carries per-stop durations so the persisted tour keeps them.

### Request

```json
{
  "stops": [
    { "lat": 48.8566, "lng": 2.3522, "duration_s": 600 },
    { "lat": 48.8600, "lng": 2.3400, "duration_s": 300 }
  ],
  "mode": "driving",
  "loop": true
}
```

- `stops`: 2–10 items; each `lat` ∈ [-90,90], `lng` ∈ [-180,180], `duration_s`
  unsigned int (seconds). Replaces the former bare `coordinates` array. `mode`, `loop`
  optional as before.
- Validation errors (`<2`/`>10`, out-of-range, missing `duration_s`) → `422`.

### Responses (done payloads gain the persisted id)

**200** (cache hit — tour persisted synchronously):

```json
{ "status": "done", "data": { "id": 42, "ordered_stops": [ … ], "total_distance_m": 0, "total_duration_s": 0 } }
```

**202** (miss): `{ "status": "pending", "job_uuid": "…" }` (unchanged).

**200** (cache hit but the tour could **not** be saved):

```json
{ "status": "failed", "error": { "code": "persist_failed", "message": "The optimized route could not be saved. Please try again." } }
```

The `TourOptimized` broadcast `data` and the `GET /api/tour/status/{uuid}` `done`
payload likewise include `id` (the persisted tour). A **job-path** persist failure is
reported through the existing `TourOptimizationFailed` broadcast / `status:failed` poll
payload with code `persist_failed`. Existing TSP-failure broadcasts unchanged.

### Persist-failure guarantees (FR-014 / research R10)

- A persist failure (either path) is **logged** with context and **surfaced** to the user
  as `persist_failed`; it is never a silent unsaved route nor a generic crash.
- The persist is atomic (no partial tour/stops). The TSP result is cached **before** the
  save is attempted, so a retry is a cache hit that re-attempts only the save.
- A route that failed to persist has no `id`, never enters the client `done` state, and is
  therefore never offered for assignment.

### Guarantees

- Exactly one `tours` row (+ its `stops`) is created per optimization result, in a
  transaction, on the cache-hit path or inside the job — never duplicated by the
  broadcast+poll dual-settle.
- `stops.position` reflects the optimized order; `duration_s` is preserved per stop.

## `POST /api/tour/geometry` (optional `tour_id`)

Unchanged behavior for the map path; when `tour_id` is present the road totals are
persisted onto that tour.

### Request

```json
{ "tour_id": 42, "stops": [[48.8566,2.3522],[48.8600,2.3400]], "mode": "driving", "loop": true }
```

### Effect

- Returns the same per-leg geometry + `total_distance_m` / `total_duration_s` as before.
- When `tour_id` is given, owned by the user, and the trace produced non-null totals,
  the **controller** (not the pure `trace()`) sets that tour's `travel_duration_s` and
  `total_distance_m` to the road totals. 2-point tours ARE traced and updated too — the
  seed is only replaced when totals are non-null; an un-traceable tour (a failed leg →
  null totals) leaves the seed in place. A missing/foreign/non-owned `tour_id` is ignored
  for persistence (the trace still returns) — never a hard failure of the map path.

## `GET /api/tour/drivers?mode=<mode>&date=<YYYY-MM-DD>` (adds `assigned_seconds`)

Same filtering as 006/011 (mode + the date's weekday). Each driver gains their
committed load for the queried date.

```json
{
  "data": [
    { "id": 12, "name": "Amélie Durand", "image_url": null, "modes": ["driving"], "assigned_seconds": 5400 }
  ]
}
```

- `assigned_seconds`: `Σ (travel_duration_s + Σ stop.duration_s)` over the driver's
  tours assigned for `date` (0 when none). Computed via an indexed aggregate over
  `driver_tour ⋈ tours ⋈ stops`.
- The frontend renders each row's **projected hours** as
  `assigned_seconds + currentTourTotalS` (the on-screen tour total), formatted h/min.
