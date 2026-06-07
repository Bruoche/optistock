# Contract: Road-Accurate Route Tracing

**Date**: 2026-06-07

## Our endpoint — `POST /api/tour/route`

- Auth: session (web middleware), same as 001. Consider `throttle` (e.g. 30/min/user).
- Synchronous (no queue) — the upstream `/route` call is fast.

### Request
```json
{
  "stops": [[48.8566, 2.3522], [45.7640, 4.8357], [43.2965, 5.3698]],
  "mode": "driving"
}
```
- `stops`: ordered optimized stops (2..10), `[lat,lng]`, lat ∈ [-90,90], lng ∈ [-180,180].
- `mode`: one of `driving|walking|trucking`; MUST match the optimization mode (default `driving`).
- Closed tour: the server appends the return leg (last→first); the client does not send it.

### Responses
- `200`:
```json
{
  "legs": [
    { "ok": true, "coordinates": [[48.8566,2.3522], [48.85,2.35]], "distance_m": 465000, "duration_s": 16800 },
    { "ok": false }
  ],
  "total_distance_m": 930000,
  "total_duration_s": 33600
}
```
  - `total_*` are `null` if any leg failed (FR-008).
- `422`: invalid `stops`/`mode`.
- `401`: unauthenticated.

## Upstream — OpenStreet `/route`

`GET {OPENSTREET_ROUTE_URL}?origin=lat,lng&destination=lat,lng&mode=driving&key=...`

Response:
```json
{ "polyline": "<google encoded polyline>", "total_distance": 465000, "total_time": 16800, "status": 0 }
```
- Success: `status` is `0` or `"OK"`. Failure codes: `SYNTAX_ERROR`, `LIMIT_REACHED`, `WRONG_KEY`,
  `REQUEST_DENIED` (→ logged leg failure, `ok:false`).
- `polyline`: Google encoded polyline (precision 5 assumed) → decoded server-side to `[[lat,lng],…]`.
- `total_distance` metres; `total_time` seconds (assumed).
- **Key never leaves the server** (spec FR-011).

## Invariants

- Front makes exactly **one** call to `/api/tour/route` per tour; the N upstream calls are server-side.
- Geometry is a pure enhancement: a non-200 or network error on `/api/tour/route` leaves the 001
  straight-line result + initial estimate intact (FR-005).
- A response for a superseded tour is ignored by the front (FR-010).
