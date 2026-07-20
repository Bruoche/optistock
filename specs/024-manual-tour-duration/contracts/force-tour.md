# Contract: Force Tour (NEW)

## `POST /api/tour/force`

Synchronous. Writes a tour in the dispatcher's current stop order with a manually supplied drive duration. No upstream routing call. Auth via session cookie (same as optimize); `throttle:tour-read`.

### Request

```jsonc
{
  "stops": [ { "lat": 48.85, "lng": 2.35, "duration_s": 600 }, ... ],  // 2–10, same rules as optimize
  "mode": "trucking",            // optional, enum, default trucking
  "loop": true,                  // optional, boolean, default true
  "tour_id": 42,                 // optional — edit an owned, UNASSIGNED tour in place (feature 020 semantics)
  "travel_duration_s": 5400      // REQUIRED integer 1..86400 — the manual tour DRIVE duration (seconds)
}
```

### Responses

| Status | Body | When |
|--------|------|------|
| `200` | `{status:'done', data:{id, ordered_stops:[{lat,lng,order}], total_distance_m:null, total_duration_s:<travel_duration_s>}}` | tour written (create or overwrite-in-place) |
| `200` | `{status:'failed', error:{code:'persist_failed', message}}` | save failed / vanished edit target — logged, never a silent create (FR-008) |
| `422` | Laravel validation errors | bad/absent stops, out-of-range coord, missing/zero/negative/over-max `travel_duration_s`, or **assigned** `tour_id` |
| `404` | not found | `tour_id` foreign or missing (never confirms a foreign id) |
| `429` | throttled | rate limit |

- `ordered_stops` order = **input order** (index), no reorder.
- `data.total_duration_s` = the **driving-only** manual seconds (`= travel_duration_s`), exactly as the optimize payload reports the `/tsp` driving total — **NOT** `Tour::total_duration_s` (the model accessor = drive + stop seconds). Per-stop seconds are added later by the frontend, same as for an optimized tour.
- `data` shape is byte-compatible with the optimize `done` payload → the frontend settles it through the same `done` path.
- Side effects: one transactional `tours` + `stops` write; **no** cache write, **no** job dispatch, **no** `TourOptimized`/`TourOptimizationFailed` broadcast. A persistence failure is logged with context.

## Frontend contract

### Reveal (spec FR-001/FR-003)

- The drive-duration field + **Force Tour** button render **only** while `state.status === 'failed'`. Never shown in `idle`/`submitting`/`pending`/`done`.
- On failure the editing view already re-renders with the placed stops intact; the field + button appear in `TourControlBar` alongside the existing controls.

### Field

- Number input, **minutes**, whole numbers; empty/NaN/negative blocked, floored, clamped to `MAX_TOUR_DURATION_MINUTES` (1440). Styled with the existing input pattern + palette variables (no raw color).
- **Force Tour** disabled until a valid (≥1 min) duration is entered and there are ≥ 2 stops.

### Action

- `forceTour(mode, loop, durationMinutes)` → `POST /api/tour/force` with `travel_duration_s = durationMinutes*60` (+ `tour_id` when editing).
- `200 done` → settle `done` with `forced: true`; `200 failed` / `422` / `429` / network → surface via the existing `settleFailed` toast (stays in `failed`, field remains available to retry).

### Transparency (spec US2 / FR-013/FR-014)

- A forced tour's result view shows the drive duration with a **"Manually entered"** badge (driven by `forced: true`).
- `total_distance_m: null` → distance shown as unknown (existing behavior), never `0`.
- Road geometry traces as usual; with the API down, legs fall back to straight segments + "route unavailable" metrics (existing behavior) — no new handling.
