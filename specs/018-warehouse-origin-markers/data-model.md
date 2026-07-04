# Data Model: Warehouse & Origin Map Markers

No database change. Two additive read-only fields on the drivers endpoint row + their frontend
view-model and marker display. Coordinates are `[lat, lng]` pairs, matching the `legs` path format.

## API field additions — `GET /api/tour/drivers` row

| Field | Type | Meaning | Source (already in the row closure) |
|-------|------|---------|-------------------------------------|
| `warehouse_coordinate` | `[number, number]` (`[lat,lng]`) | The driver's warehouse — the day's fixed start/end. | `$warehouse = $driver->warehouse->coordinate` |
| `previous_tour_end` | `[number, number] \| null` | End of the driver's last prior tour that day; `null` when none (departs from warehouse). | `$incoming = incomingPoint(...)`; `null` when `$incoming->isSameAs($warehouse)` |

All prior fields (`id`, `warehouse_name`, `projected_seconds`, `time_to_tour`, `time_from_tour`,
`start_index`, `legs`, …) are unchanged. `projected_seconds` and the routing-call count are
unchanged (no new routing).

## Frontend view-model — `Driver` (types/tour.ts)

```ts
/** The driver's warehouse [lat,lng] — the projected day's start/end (feature 018). */
warehouseCoordinate: [number, number];
/** End of the driver's last prior tour that day [lat,lng]; null when the driver departs from the
 *  warehouse (no prior tour) — the "0" origin marker is drawn only when non-null (feature 018). */
previousTourEnd: [number, number] | null;
```

Mapped in `use-tour-drivers.ts`: `warehouseCoordinate: driver.warehouse_coordinate`,
`previousTourEnd: driver.previous_tour_end`.

## Display — `WorkdayMarkers` (rendered only while a driver is selected)

| Marker | Position | Glyph | When |
|--------|----------|-------|------|
| Warehouse | `driver.warehouseCoordinate` | lucide `Building2` | Always (a driver is selected) |
| Origin "0" | `driver.previousTourEnd` | `0` | Only when `previousTourEnd !== null` |

Both: same circle as numbered stops (`size-6 rounded-full flex items-center justify-center shadow`,
`anchor="bottom"`), fill `bg-route-neutral/50` (50% on the fill only), glyph
`text-route-neutral-foreground` (a new theme-stable near-white palette role added for legibility on
the dark fill in both themes).

## Invariants

- `warehouse_coordinate` is always present for a driver row.
- `previous_tour_end === null` ⟺ the driver has no prior tour that day ⟺ the "0" marker is absent
  (the warehouse is the origin, so the two would otherwise coincide).
- When non-null, `previous_tour_end` equals the last prior tour's `end` and never equals the
  warehouse (guaranteed by the `isSameAs` gate).
- The markers exist only for the selected driver; none render when no driver is selected.
- Neither field feeds `projected_seconds`, legs, ordering, or any routing call.
