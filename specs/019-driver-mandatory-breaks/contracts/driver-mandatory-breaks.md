# Contract: Mandatory Driver Breaks

`GET /api/tour/drivers?mode={mode}&date={YYYY-MM-DD}&tour={id}`

Request and validation unchanged. One field changes meaning, one is added.

## Changed / added response fields (per `data[]` row)

```jsonc
{
  // …existing fields (id, warehouse_name, time_to_tour, time_from_tour, start_index,
  //   warehouse_coordinate, previous_tour_end, legs, …) unchanged…
  "projected_seconds": 26100,   // CHANGED: working time + with-candidate mandatory break
  "added_break": 2700           // NEW: marginal break the candidate adds (seconds, ≥ 0)
}
```

| Field | Type | Rule |
|-------|------|------|
| `projected_seconds` | `int` | Working time **plus** `breakWith = max(workdayBreak, drivingBreak)` for the with-candidate day. Equals the pre-019 value only when that break is 0. Still `projected_incomplete`-flagged when travel is unknown. |
| `added_break` | `int` (`≥ 0`) | `breakWith − breakWithout`, where `breakWithout` is the break of the day **without** the candidate (warehouse → prior tours → warehouse). `0` when the candidate crosses no threshold; equals `breakWith` when the driver has no prior tours. |

## Break definition (both days)

- `workdayBreak` = `0` if total ≤ 6 h, `30 min` if 6 h < total ≤ 9 h, `45 min` if total > 9 h.
- `drivingBreak` = `45 min × floor(driving / 4h30)`; driving = total − all stop/service seconds.
- day break = `max(workdayBreak, drivingBreak)`.

## Frontend mapping

| API field | `Driver` field | Display |
|-----------|----------------|---------|
| `added_break` | `addedBreak: number` | "Required break" figure, `+${formatDurationHm}`, orange (`--primary`), leftmost of the group, shown only when `> 0`. |
| `projected_seconds` | `projectedSeconds` | "Projected workday" — unchanged rendering, now a larger value when a break applies. |

## Guarantees

- `added_break ≥ 0`; `added_break === 0` ⟺ no Required break figure.
- Break is `max`, never the sum of the two rules; measured on working/driving time only.
- No change to `time_to_tour`, `time_from_tour`, `legs`, ordering, or the marker fields.
