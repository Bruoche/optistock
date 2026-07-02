# Contract: Assign With Start/End Stop (POST /api/tour/{tour}/assign)

**Feature**: 013-inter-tour-travel

Extends the 012 assign endpoint: it now records **which stop the tour starts and ends at**
and the driver's **sequence** for the day, using the start the drivers payload already
selected (no recomputation).

## Request

```
POST /api/tour/{tour}/assign
{ "driver_id": 7, "date": "2026-07-06", "start_index": 3 }
```

- `driver_id`, `date` — unchanged (012): driver must exist and be **eligible** (mode +
  the date's weekday); tour must be **owned** (else 404).
- `start_index` — **new, required integer.** The candidate start stop `position` chosen for
  this driver (the `start_index` from `GET /api/tour/drivers`). Validated in
  `AssignTourRequest` to be a **legal start position** for the bound tour — one of
  `Tour::startCandidates()` positions (looping → any stop position; one-way → the first or
  last position). Never trusted blindly even though the server picked it.

## Server flow

1. `AssignTourRequest` authorizes ownership (404) and validates `driver_id` eligibility +
   `start_index` legality.
2. Resolve the **start** `Stop` at `start_index`; deduce the **end** via
   `Tour::endStopForStart` (looping → same stop; one-way → opposite endpoint).
3. `sequence = max(driver_tour.sequence for this driver + date) + 1`.
4. Upsert the `driver_tour` row (idempotent `sync` on the unique `tour_id`) with
   `date`, `start_latitude/longitude`, `end_latitude/longitude`, `sequence`.

## Response `200`

```jsonc
{
  "data": {
    "tour_id": 42,
    "driver_id": 7,
    "date": "2026-07-06",
    "start_index": 3,
    "sequence": 1
  }
}
```

## Errors

| Status | When                                                                     |
|--------|--------------------------------------------------------------------------|
| `422`  | Ineligible/unknown driver, invalid `date`, or `start_index` not a legal start position for the tour. |
| `404`  | Tour unknown or not owned by the user.                                   |
| `401`  | Unauthenticated.                                                         |

Idempotency unchanged: a concurrent double-assign racing the unique `tour_id` is caught and
treated as success (012).
