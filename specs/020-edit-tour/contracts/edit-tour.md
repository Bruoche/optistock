# Contracts: Edit Tour

## 1. `POST /api/tour/optimize` — additive `tour_id`

Unchanged behavior for creation; adds an optional `tour_id` that switches the persistence step to update-in-place.

### Request (additive field only)
```jsonc
{
  "stops": [ { "lat": 48.85, "lng": 2.35, "duration_s": 600 }, … ],  // 2–10, existing rules
  "mode": "trucking",          // optional, existing
  "loop": true,                // optional, existing
  "tour_id": 42                // NEW, optional
}
```

### `tour_id` validation
- `sometimes | integer`
- MUST reference a tour owned by the authenticated user — otherwise **404** (never confirm a foreign id exists; same convention as `AssignTourRequest`).
- The tour MUST be **unassigned** (no `driver_tour` row) — otherwise **422** with a message that an assigned tour cannot be edited.

### Responses (shape unchanged from today)
- **200 `done`**: cache hit persisted. When `tour_id` was sent, `data.id === tour_id` and that row was updated (not a new row).
- **202 `pending`** `{ job_uuid }`: queued; the update happens in the job. The eventual `TourOptimized` broadcast / status `done` carries `data.id === tour_id`.
- **200 `failed`** `{ error }`: `persist_failed` if the update could not be saved (including a target tour that disappeared/became assigned before the queued job's update — logged, never a silent create).
- **422**: invalid stops (existing) or an assigned `tour_id`.
- **404**: `tour_id` not owned / not found.

### Invariants
- With a valid `tour_id`, total tour count is unchanged across the request (SC-002).
- Without `tour_id`, behavior is byte-for-byte the current create path.

---

## 2. `GET /tour/{tour}/edit` — Inertia page with `editTour` prop

Renders the same `tour/optimize` Inertia page as the plain tour page, but hydrated for editing.

### Preconditions
- Auth + verified (same middleware group as the tour page).
- `{tour}` MUST be owned by the user → **404** otherwise.
- `{tour}` MUST be unassigned → redirect back to the tour page (or 404) if assigned; an assigned tour is not editable (FR-009).

### Page props
```jsonc
// plain tour page:  editTour = null
// edit route:
{
  "editTour": {
    "id": 42,
    "mode": "trucking",
    "loop": true,
    "stops": [
      { "lat": 48.85, "lng": 2.35, "duration_minutes": 10 },
      …                                  // ascending position order
    ]
  }
}
```
- `duration_minutes = duration_s / 60`.
- No `date` key (unassigned tours carry no date; page defaults to today).

### Frontend contract (optimize page)
- When `editTour` is present: seed the stop list from `editTour.stops`, set `mode`/`loop` controls from the prop, remember `editTour.id`, and land in the **editing** view (not the result view).
- The Optimize action then POSTs `/api/tour/optimize` **with** `tour_id: editTour.id`.
- When `editTour` is null: current new-tour behavior, no `tour_id` sent.

---

## 3. Result view action row

- Three `ActionButton`s in order: **New**, **Edit**, **Assign**.
  - **New** — relabel of "New tour"; unchanged confirm-then-reset behavior.
  - **Edit** — `router.visit('/tour/{result.id}/edit')`. Available only for the shown (unassigned) optimized tour; disabled while an optimization is pending.
  - **Assign** — relabel of "Assign Driver"; unchanged driver-assignment behavior (still disabled until a driver is selected).
