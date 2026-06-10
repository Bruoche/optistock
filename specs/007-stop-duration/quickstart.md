# Quickstart: Per-Stop Delivery Duration & Tour Duration Total

Manual verification of the feature end to end.

## Prerequisites

- App running (`composer dev` + `php artisan reverb:start`), `OPENSTREET_API_KEY` set.
- Logged in; on `/tour`.

## 1 — Per-stop duration input (US1)

1. Click the map to add 3+ stops.
2. **Expect**: each stop row shows a minutes field pre-filled with **10**.
3. Change one stop to `20`, another to `0`.
4. **Expect**: each edited value sticks; other stops keep their own values.
5. Remove a stop.
6. **Expect**: remaining stops keep their durations unchanged.

## 2 — Two totals after optimizing (US2)

1. With stops carrying durations of `6`, `10`, `24` minutes (sum = 40), pick a mode + loop, click **Optimize**.
2. When the result shows, **expect two figures**:
   - **Time on road** — the existing travel duration (may briefly read "Unavailable" / the estimate before
     the road trace lands, then the road-accurate value).
   - **Tour duration** — `Time on road + 40 min`.
3. With a real ~44-min drive: **Time on road** ≈ 44 min, **Tour duration** ≈ 84 min (1 h 24 min).

## 3 — Delivery time unavailable = 0 (FR-011, worked example)

1. Add exactly **2** stops with durations `15` and `10` (no road metrics yet for a 2-point tour).
2. Optimize.
3. **Expect immediately**: Time on road = "Unavailable", **Tour duration = 25 min** (`0 + 15 + 10`).
4. When the road trace responds (e.g. 20 min): Time on road = 20 min, **Tour duration = 45 min**.

## 4 — Duration edits don't re-hit the upstream API (CR-4)

1. Optimize a route; note it completes.
2. Reset, re-add the **same** coordinates but change only the durations, optimize again.
3. **Expect**: the route result returns from cache fast (no multi-minute wait), but **Tour duration**
   reflects the **new** durations (fresh `wait_time`).

## 5 — Validation (robustness)

- A `durations` array whose length ≠ coordinates, or a negative / non-integer / > 1440 value, → `422`
  (exercise via the network tab or a direct request); the UI keeps a valid value rather than breaking.
