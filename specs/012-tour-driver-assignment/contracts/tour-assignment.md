# Contract: Tour Assignment

## HTTP — `POST /api/tour/{tour}/assign`

Auth: required (`auth` group). Throttled (`throttle:tour-read`). `{tour}` is the
persisted tour's id (route-model-bound).

### Request

```json
{ "driver_id": 12, "date": "2026-07-06" }
```

Validated by `AssignTourRequest`:
- **Ownership** → `authorize()` passes only when the bound tour's `user_id` === the
  requesting user; otherwise the request is denied and surfaces as `404` (a foreign tour
  id is not confirmed to exist).
- `driver_id` → `required`, exists in `drivers`, and **eligible** for this tour: the
  driver supports the tour's `delivery_mode` AND is scheduled on `date`'s weekday
  (006/011), re-checked server-side.
- `date` → `required|date`.

### Effect

- `updateOrCreate` a `driver_tour` row keyed by `tour_id` (`driver_id`, `date`).
  Idempotent; a second call re-targets the same tour (forward-compatible with
  re-assignment). A concurrent double-assign that races the unique-`tour_id` constraint is
  caught and treated as an idempotent success (re-read the row), not a 500 (RB5).

### Responses

- **200**: `{ "data": { "tour_id": 42, "driver_id": 12, "date": "2026-07-06" } }`.
- **422**: invalid/ineligible `driver_id` or bad `date` (surfaced to the client; no
  assignment recorded — FR-011).
- **404**: unknown tour **or a tour not owned by the requesting user** (ownership; a
  foreign tour id is not confirmed to exist).
- **401**: unauthenticated.

### Guarantees

- On success the assignment persists and is reflected in that driver's
  `assigned_seconds` for the date on subsequent driver lists (SC-005).
- On failure nothing is recorded and the client is not navigated away (FR-011).

## UI contract — clickable driver list + confirmation

- Each `DriverList` row is a **button** (keyboard-focusable) showing, in addition to
  name + mode icons (006), the driver's **projected hours** for the date —
  `assignedSeconds + currentTourTotalS`, formatted like the tour-duration figure (FR-006/008).
- Clicking a row opens a shared **confirmation dialog** (shadcn `AlertDialog`) naming
  the driver and the delivery (FR-002); only one is open at a time.
- **Confirm** → `POST /api/tour/{id}/assign`; on success the app returns to the cleared
  route creation menu (`reset()`), ready for a new tour (FR-003/FR-004).
- **Cancel / dismiss** → dialog closes, no assignment, presentation + list unchanged
  (FR-005).
- **Assign failure** → an error toast; the manager stays on the presentation phase
  (FR-011). Styling: role-named colors + shared primitives (constitution VI).
