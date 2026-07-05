# Data Model: Edit Tour

**No schema change.** Editing reuses the existing `tours` and `stops` tables; the only new behavior is *update in place* instead of *insert*.

## Entities (existing, unchanged shape)

### Tour (`tours`)
- `id`, `user_id`, `delivery_mode_id`, `loop`, `travel_duration_s`, `total_distance_m`
- **Edit semantics**: when optimizing with a `tour_id`, this row is UPDATED (`delivery_mode_id`, `loop`, `travel_duration_s`, `total_distance_m`) rather than created. `id` and `user_id` are preserved.
- **Editable precondition**: has no `driver_tour` association (unassigned). Enforced at request validation.

### Stop (`stops`)
- `id`, `tour_id`, `latitude`, `longitude`, `duration_s`, `position`
- **Edit semantics**: on an update, the tour's existing stops are DELETED and the freshly ordered stops RE-CREATED, in the same transaction as the tour update. Stop ids are not preserved (they carry no external references).

### DeliveryMode (`delivery_modes`) — unchanged
- Looked up / first-or-created by label exactly as in the create path.

## Transient shapes (not persisted)

### Optimize request payload (additive field)
```
{
  stops: [{ lat, lng, duration_s }],   // existing
  mode?: DeliveryMode,                  // existing
  loop?: boolean,                       // existing
  tour_id?: number                      // NEW — target tour to update (owned + unassigned)
}
```
- `tour_id` optional. Present → update that tour. Absent → create (current behavior).
- Validation: `sometimes`, integer, exists, owned by caller, unassigned. Foreign/missing → 404; assigned → 422.

### `editTour` page prop (server → optimize page)
```
{
  id: number,
  mode: DeliveryMode,          // from tour.deliveryMode.label
  loop: boolean,
  stops: [                     // in ascending position order
    { lat: number, lng: number, duration_minutes: number }
  ]
}
```
- `duration_minutes` = `stop.duration_s / 60` (matches the client `Stop.durationMinutes`).
- Present only on the `tour/{tour}/edit` route; `null` on the plain optimize page.
- Date intentionally absent (see research Decision 5).

## State transitions

```
[unassigned Tour] --Edit--> optimize page hydrated from editTour prop
       ^                                   |
       |                                   | re-optimize (POST optimize with tour_id)
       +-----------------------------------+   (same Tour id, stops replaced)

optimize page (new) --optimize (no tour_id)--> [new unassigned Tour]
[unassigned Tour] --Assign--> [assigned Tour]   (no longer editable)
```

## Validation rules (from requirements)

- FR-007/FR-008: a `tour_id` update MUST NOT insert a new tour → single row, `Tour::count()` unchanged across an edit-optimize (SC-002).
- FR-009: only unassigned tours are editable → assigned `tour_id` rejected (422).
- FR-010: the existing 2–10 stop + coordinate + duration rules apply unchanged to an edit-optimize.
- FR-005/SC-003: 100% of stops (coord + duration), mode, and loop are restored from the tour into the page.
