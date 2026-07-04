# Contract: Warehouse & Origin Marker Coordinates

`GET /api/tour/drivers?mode={mode}&date={YYYY-MM-DD}&tour={id}`

Additive only. Request, validation, and every prior response field are unchanged; the routing-call
count and `projected_seconds` are unchanged (both new values are locals already computed for the
row — no new `/route` call, no new query).

## Added response fields (per `data[]` row)

```jsonc
{
  // …all existing fields (id, warehouse_name, projected_seconds, time_to_tour, time_from_tour,
  //   start_index, legs, …) unchanged…
  "warehouse_coordinate": [48.8566, 2.3522],       // [lat, lng] — always present
  "previous_tour_end": [48.87, 2.34]               // [lat, lng], OR null when no prior tour
}
```

| Field | Type | Rule |
|-------|------|------|
| `warehouse_coordinate` | `[number, number]` | Always present; the driver's warehouse `[lat,lng]`. |
| `previous_tour_end` | `[number, number] \| null` | `null` iff the driver's incoming point equals the warehouse (no prior tour that day); otherwise the last prior tour's end `[lat,lng]` (never equal to the warehouse). |

## Frontend mapping

| API field | `Driver` field | Marker |
|-----------|----------------|--------|
| `warehouse_coordinate` | `warehouseCoordinate: [number, number]` | Warehouse marker (Building2), drawn whenever the driver is selected. |
| `previous_tour_end` | `previousTourEnd: [number, number] \| null` | "0" origin marker, drawn only when non-null. |

## Guarantees

- No new routing call and no change to `projected_seconds` versus the pre-018 response.
- `previous_tour_end` null ⟺ no "0" marker; the warehouse marker is always available for a row.
- Coordinates use the same `[lat, lng]` ordering as the existing `legs[].path`.
