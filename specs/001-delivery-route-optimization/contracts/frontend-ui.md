# Contract: Front-End ↔ Backend (Delivery Route Optimization)

**Date**: 2026-06-07 | The backend endpoints/events below already exist and are verified. This documents what the front-end consumes — no backend changes required.

## HTTP

### POST `/api/tour/optimize`
- Auth: session (web middleware) + `throttle:tour-optimize` (10/min/user).
- Request: `{ "coordinates": [[lat, lng], ...] }` — 2..10 pairs, lat ∈ [-90,90], lng ∈ [-180,180].
- Responses:
  - `200` (cache hit): body = `{ ordered_stops, total_distance_m, total_duration_s }` → render immediately, no WS.
  - `202` (cache miss): body = `{ job_uuid }` → enter `pending`, subscribe + poll.
  - `422`: validation error (invalid/insufficient coords) → surface inline (FR-006).
  - `401`: unauthenticated.
  - `429`: rate limited.

### GET `/api/tour/status/{job_uuid}`
- WS fallback. Returns cached status: `pending` | `done` (+ result) | `failed` (+ error), or `404` if unknown.

## WebSocket (Reverb / Echo)

- Channel: **private** `App.Models.User.{id}` (authorized in `routes/channels.php`).
- Client: `Echo.private('App.Models.User.' + userId)`.
- Events (filter by `job_uuid` — a channel may carry multiple jobs):

```js
.listen('.TourOptimized', (e) => {
  // e = { job_uuid, data: { ordered_stops, total_distance_m, total_duration_s } }
  if (e.job_uuid === jobUuid) renderResult(e.data);
})
.listen('.TourOptimizationFailed', (e) => {
  // e = { job_uuid, error: { code, message } }
  if (e.job_uuid === jobUuid) showError(e.error);
})
```

- Note the leading dot in `.TourOptimized` (broadcastAs / class basename, no namespace prefix).
- On terminal state: unsubscribe (`Echo.leave('App.Models.User.' + userId)` or `.stopListening`).

## Map (external)

- OSM-compatible vector tile style for MapLibre GL. No backend proxy in this feature.

## Invariants the front-end must uphold

- Never POST with <2 or >10 stops (Optimize disabled otherwise) — mirrors server validation, avoids guaranteed 422.
- Always have a WS-fallback poll so a dropped socket never leaves the UI stuck in `pending` (spec edge cases).
- Coordinates sent as `[lat, lng]` order (matches backend normalizer + TSP client).
