# Contract: Optimize request/response with stop durations & `wait_time`

Extends the feature-001 optimize contract. Only the **deltas** are listed; everything else is unchanged.

## `POST /api/tour/optimize` (auth, throttle:tour-optimize)

### Request (delta)

```jsonc
{
  "coordinates": [[48.85, 2.35], [48.86, 2.34]],   // unchanged: 2–10 [lat,lng] pairs
  "mode": "trucking",                               // unchanged (optional)
  "loop": true,                                     // unchanged (optional)
  "durations": [15, 10]                             // NEW (optional): minutes per coordinate, aligned by index
}
```

- `durations`: optional array. When present, its size MUST equal `coordinates` size.
- `durations.*`: `integer`, `min:0`, `max:1440` (minutes).
- When omitted → server defaults each stop to **10** minutes.
- `durations` is **not** forwarded to the OpenStreet API and **not** part of the optimize cache key.

### Response (delta)

`wait_time_s` (integer seconds = `sum(durations) * 60`) is added as a **sibling** of the existing body,
computed from the request on every call (cache hit or miss).

**200 — cache hit / trivial tour**
```jsonc
{
  "status": "done",
  "data": { "ordered_stops": [...], "total_distance_m": null, "total_duration_s": null },
  "wait_time_s": 1500
}
```

**202 — queued**
```jsonc
{
  "status": "pending",
  "job_uuid": "…",
  "wait_time_s": 1500
}
```

- **422**: `durations` size mismatch with `coordinates`, or a `durations.*` not an integer / negative / > 1440.
- **401 / 429**: unchanged.

### Unchanged

- `GET /api/tour/status/{job_uuid}` — **no** `wait_time_s`; the frontend already holds it from the 202.
- `TourOptimized` broadcast — unchanged (carries `data` only).
- `POST /api/tour/geometry` — unchanged (durations are irrelevant to road geometry).

## Frontend display contract (`ResultSummary`)

Two figures, both formatted by the existing `formatDuration(seconds)`:

| Label          | Value                                   | Null/unavailable handling                          |
| -------------- | --------------------------------------- | -------------------------------------------------- |
| **Time on road** | `roadMetrics?.duration_s ?? result.total_duration_s` | `null` → "Unavailable" (existing behavior) |
| **Tour duration** | `(deliveryS ?? 0) + waitTimeS`         | never unavailable; ≥ `waitTimeS`                   |

Worked example (matches the spec): 2-point tour, durations `[15, 10]` ⇒ `wait_time_s = 1500`.
- Before legs respond: Time on road = "Unavailable", Tour duration = `0 + 1500` = **25 min**.
- After trace responds with 1200 s: Time on road = **20 min**, Tour duration = `1200 + 1500` = **45 min**.

## Stop list input contract (`StopList`)

- Each stop row shows a numeric **minutes** input, defaulting to `10` for newly added stops.
- Editing one stop's value updates only that stop (`onDurationChange(id, minutes)`); other stops and their
  values are unaffected.
- Locked (greyed, non-interactive) while a tour is optimizing, like the rest of the list.
