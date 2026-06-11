# Contract: Stop durations & the two duration figures (frontend-only)

This feature is **frontend-only**. There is **no API change**. The contracts below are the frontend UI
contracts the feature adds.

## `POST /api/tour/optimize` — UNCHANGED

No request or response delta. The body remains `{ coordinates, mode?, loop? }`; the response remains the
feature-001 shape (200 `{status,data}` / 202 `{status,job_uuid}`). Durations are **not** sent and **no**
`wait_time_s` is returned. The status endpoint, `TourOptimized` broadcast, geometry endpoint, and `TourCache`
are likewise unchanged.

The stop total (`waitTimeS`) is computed in the browser from the stops' `durationMinutes` and used only for
display — see below.

## Frontend display contract (`ResultSummary`)

Accepts a new `waitTimeS: number` (seconds) prop. Two figures, both formatted by the component's local
`formatDuration(seconds)`:

| Label             | Value                                                | Null/unavailable handling                          |
| ----------------- | ---------------------------------------------------- | -------------------------------------------------- |
| **Time on road**  | `roadMetrics?.duration_s ?? result.total_duration_s` | `null` → "Unavailable" (existing behavior)         |
| **Tour duration** | `(deliveryS ?? 0) + waitTimeS`                       | never unavailable; ≥ `waitTimeS`                   |

Worked example (matches the spec): 2-point tour, durations `[15, 10]` ⇒ `waitTimeS = 1500`.
- Before legs respond: Time on road = "Unavailable", Tour duration = `0 + 1500` = **25 min**.
- After trace responds with 1200 s: Time on road = **20 min**, Tour duration = `1200 + 1500` = **45 min**.

## Stop list input contract (`StopList`)

- Each stop row shows a numeric **minutes** input, defaulting to `DEFAULT_STOP_DURATION_MINUTES` (10) for newly
  added stops.
- Editing one stop's value calls `onDurationChange(id, minutes)` and updates only that stop; other stops and
  their values are unaffected.
- Input is coerced to a valid value: empty/non-numeric/negative → `0`, non-integers floored, values > 1440
  clamped to 1440 (CR-2). The field always shows a valid number — never `NaN`/negative.
- Locked (greyed, non-interactive) while a tour is optimizing, like the rest of the list.

## Hook contract (`useTourOptimization`)

- `addStop` assigns `durationMinutes: DEFAULT_STOP_DURATION_MINUTES`.
- Adds `setStopDuration(id, minutes)` applying the CR-2 coercion to the matching stop only.
- Exposes a derived `waitTimeS = Σ(durationMinutes) * 60` (seconds). The optimize POST body is unchanged.
