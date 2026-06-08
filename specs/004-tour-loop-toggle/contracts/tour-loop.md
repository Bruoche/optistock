# Contract: Tour Loop Toggle

## Loop value

`loop` boolean — `true` = closed loop (default, returns to origin), `false` = open one-way (no return).
The request, the cache, and the front state all use the boolean; the job translates it to the TSP `tour`
field (`true → "closed"`, `false → "open"`) and the thin client forwards that string.

## HTTP — `POST /api/tour/optimize` (auth, throttle:tour-optimize)

**Request** (gains `loop`, on top of 003's `mode`):

```json
{ "coordinates": [[48.8566, 2.3522], [48.85, 2.34]], "mode": "trucking", "loop": false }
```

- `loop` — optional boolean. Omitted ⇒ `true` (closed). Non-boolean ⇒ `422`.

**Responses** (unchanged from 001/003):
- `200 { "status": "done", "data": TourResult }` — cache hit **for that mode + shape**.
- `202 { "status": "pending", "job_uuid": "..." }` — queued.
- `422` validation, `401` unauth, `429` throttled.

**Shape-keyed caching**: same coordinates + same mode but a different loop shape never hit each other's
cached tour or active-job lock (keys `tour:{mode}:{shape}:{hash}`, `tour:active:{userId}:{mode}:{shape}:{hash}`).

**Upstream** — the TSP query carries `tour=closed` or `tour=open` (previously always `closed`).

## HTTP — `POST /api/tour/geometry` (auth, throttle:tour-geometry)

**Request** (gains `loop`):

```json
{ "stops": [[48.8566, 2.3522], [48.85, 2.34]], "mode": "trucking", "loop": false }
```

- `loop` — optional boolean, default `true`. The front always sends the loop the tour was optimized with
  (FR-007).

**Response**: same shape as 002. When `loop=false` the service returns **one fewer leg** (no closing
`last → first` leg) and totals exclude the return.

## UI contract — loop toggle

- Lives in the editing-view control bar (003) **to the right of** the mode dropdown:
  `[ ModeSelect ] [ LoopToggle ] [ Optimize route ]`.
- `LoopToggle` (reuses shadcn `Toggle`): a pressed/unpressed button; **default on** (looped) on first
  load; clearly shows its current state; disabled while a tour is optimizing.
- Toggling updates page state only; it does **not** re-optimize or re-draw a tour already shown (FR-008).
  The new setting applies on the next **Optimize route** click.
- The loop chosen at optimize time is snapshotted with the result and reused for that tour's geometry
  trace, so the drawn route's shape matches the optimization (FR-007).
- Independent of the delivery mode (003) — any mode × either shape (FR-010).
- Styling: shared `Toggle` + role-named color variables only; no raw hex, no duplicated rules.
