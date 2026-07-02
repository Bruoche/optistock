# Contract: Chained Driver Workday (GET /api/tour/drivers)

**Feature**: 013-inter-tour-travel

Supersedes the 012 drivers payload: the projected figure is now the **full chained
workday** (warehouse → tours → warehouse, with inter-tour travel), computed server-side,
plus the **selected start** the assignment will reuse.

## Request

```
GET /api/tour/drivers?mode=<trucking|driving|walking>&date=<YYYY-MM-DD>&tour=<tourId>
```

- `mode`, `date` — unchanged (006/011).
- `tour` — **new, required.** The persisted candidate tour id. Must exist **and be owned**
  by the requesting user; a foreign/unknown id → `404` (never confirm a foreign tour id,
  mirroring the assign guard). Validated in `AvailableDriversRequest`.

## Response `200`

```jsonc
{
  "data": [
    {
      "id": 7,
      "name": "Esra Yılmaz",
      "image_url": "https://…/esra.svg",
      "modes": ["trucking", "driving", "walking"],
      "warehouse_name": "North Depot",     // NEW — where this driver comes from
      "projected_seconds": 15420,          // NEW — full chained workday (best-effort)
      "projected_incomplete": false,       // NEW — true = a leg failed to route; figure is a lower bound
      "start_index": 3                     // NEW — chosen candidate start stop position
    }
  ]
}
```

- `warehouse_name` — the driver's warehouse (mandatory link), shown on the row.
- `projected_seconds` — `int`. The **best-effort** chain
  `W→firstStart + Σ tourTotals + Σ betweenLegs + lastEnd→W` for this driver **including the
  candidate tour appended last** (assignment order). A leg that **failed to route contributes
  0** (FR-009) — so the figure is a **lower bound**, paired with `projected_incomplete`.
- `projected_incomplete` — `bool`. **`true`** when at least one leg feeding this driver's
  figure failed to route: the UI marks the figure approximate/incomplete ("at least this long,
  possibly more" — FR-015). `false` when every leg routed. Failed legs are logged server-side.
- `start_index` — the candidate tour stop `position` selected as this driver's start (the
  valid start nearest, by road time, to the driver's incoming point — warehouse or their last
  prior tour's end). The assign call sends this back so the start is **not** recomputed (R7).

### Removed vs 012

- `assigned_seconds` is gone; the client no longer adds the on-screen current-tour total —
  `projected_seconds` is complete and authoritative.

## Concurrency (FR-014)

The endpoint needs up to *drivers × valid-start* + chain legs of routing lookups. It MUST
collect the **distinct** set of legs (dedup identical warehouse/return/between legs across
drivers), fetch outstanding legs with a **capped concurrent batch** (bounded pool, so the
routing API is not flooded), populate a per-request duration map, then run each driver's chain
over the pre-fetched durations. A leg missing from the map (routing failure) → 0 +
`projected_incomplete` for any driver depending on it.

## Server flow

1. `AvailableDriversRequest` validates `mode`, `date`, and owned `tour`.
2. Load the tour (+ ordered `stops`) → `startCandidates`, `loop`, internal total seconds.
3. `Driver::available(mode, weekday)` (eager-load `warehouse`).
4. Per driver: warehouse coordinate + prior `driver_tour` rows for `date` ordered by
   `sequence` (with each tour's total) → `WorkdayEstimator::estimate(...)`.
5. Emit `warehouse_name`, `projected_seconds`, `start_index` per driver.

Travel legs go through `TravelTimeService` (per-request memoized; failure→null→unavailable).

## Errors

| Status | When                                                    |
|--------|---------------------------------------------------------|
| `422`  | Missing/invalid `mode`, `date`, or `tour`.              |
| `404`  | `tour` unknown or not owned by the user.                |
| `401`  | Unauthenticated.                                        |
