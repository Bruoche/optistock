# Contract: Delivery Mode Selection

## Allowed modes

`trucking` (default) · `driving` · `walking`. Source of truth: `App\Enums\DeliveryMode`.

## HTTP — `POST /api/tour/optimize` (auth, throttle:tour-optimize)

**Request** (gains `mode`):

```json
{ "coordinates": [[48.8566, 2.3522], [48.85, 2.34]], "mode": "walking" }
```

- `mode` — optional, one of `trucking|driving|walking`. Omitted ⇒ server uses
  `config('services.openstreet.mode')` (= `trucking`). Out-of-set ⇒ `422`.

**Responses** (unchanged from 001):
- `200 { "status": "done", "data": TourResult }` — cache hit **for that mode**.
- `202 { "status": "pending", "job_uuid": "..." }` — queued; result via `TourOptimized` broadcast /
  status endpoint.
- `422` validation, `401` unauth, `429` throttled.

**Mode-keyed caching**: identical coordinates under a different mode never hit a prior mode's cached tour
or active-job lock (keys `tour:{mode}:{hash}`, `tour:active:{userId}:{mode}:{hash}`).

## HTTP — `POST /api/tour/geometry` (auth, throttle:tour-geometry)

**Unchanged** — already accepts `mode`:

```json
{ "stops": [[48.8566, 2.3522], [48.85, 2.34]], "mode": "walking" }
```

The front now **always** sends `mode`, equal to the mode the displayed tour was optimized with
(FR-007). Per-leg / whole-tour fallback + logging unchanged from 002.

## UI contract — mode dropdown

- A control bar sits directly **beneath the map**, in the editing (pre-result) view. Layout:
  `[ ModeSelect ]` on the **left**, the **Optimize route** button to its right.
- `ModeSelect` (reuses shadcn `Select`): three options in `DELIVERY_MODES` order; **defaults to
  Trucking** on first load; always shows the current selection; disabled while a tour is optimizing.
- Selecting a mode updates page state only; it does **not** re-optimize or re-trace a tour already shown
  (FR-008). The new mode applies on the next **Optimize route** click.
- The mode chosen at optimize time is snapshotted with the result and reused for that tour's geometry
  trace, guaranteeing the polyline matches the optimization mode (FR-007).
- Styling: shared `Select` component + role-named color variables only; no raw hex, no duplicated rules
  (constitution VI).
