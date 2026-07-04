# Contract — Available drivers with bracketing road times (v4)

`GET /api/tour/drivers?mode={mode}&date={YYYY-MM-DD}&tour={tourId}`

Supersedes the 014/015 version by **adding two fields** per driver: `time_to_tour` and
`time_from_tour`. Everything else (auth, validation, 404 on foreign/unknown tour, dedup +
capped-pool routing, `projected_seconds` / `projected_incomplete` / `start_index` / `legs`
semantics and values) is **unchanged**. No new routing calls are made — both values are read
from connections already fetched for the projected total.

## Response `200` (added fields shown; existing fields elided)

```json
{
    "data": [
        {
            "id": 7,
            "name": "Nadia Benali",
            "warehouse_name": "Gennevilliers",
            "projected_seconds": 14520,
            "projected_incomplete": false,
            "start_index": 2,
            "time_to_tour": 1320,
            "time_from_tour": 1080,
            "legs": [ /* unchanged */ ]
        }
    ]
}
```

## Field semantics

| Field | Type | Definition |
|-------|------|------------|
| `time_to_tour` | `int \| null` | Road seconds from the driver's incoming point — the **end of their last already-assigned tour that day**, or their **warehouse** if none — to the candidate tour's chosen **start**. `0` if coincident; `null` if that connection is unroutable. |
| `time_from_tour` | `int \| null` | Road seconds from the candidate tour's chosen **end** back to the **warehouse** (i.e. "Road to warehouse"). `0` if coincident; `null` if unroutable. |

Both are among the durations summed into `projected_seconds`; when either is `null`,
`projected_incomplete` is already `true` (unchanged behavior).

## Frontend mapping

`time_to_tour → Driver.timeToTour`, `time_from_tour → Driver.timeFromTour` (both
`number | null`). Rendered on the row, right side, left→right: **Road to tour**,
**Road to warehouse**, **Total projected workday**; `null` → "Unavailable".
