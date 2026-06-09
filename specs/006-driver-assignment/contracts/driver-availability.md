# Contract: Driver Availability

## HTTP — `GET /api/tour/drivers`

Auth: required (session cookie, `auth` group — same as the other tour endpoints). Throttled.

### Request

| Param | In    | Required | Value                                   |
| ----- | ----- | -------- | --------------------------------------- |
| mode  | query | yes      | `trucking` \| `driving` \| `walking`    |

Validated by `AvailableDriversRequest`: `mode` ∈ `App\Enums\DeliveryMode`.

### Responses

**200 OK** — drivers whose modes include `mode`, ordered by `name` ascending:

```json
{
  "data": [
    {
      "id": 12,
      "name": "Amélie Durand",
      "image_url": "http://localhost/storage/drivers/amelie.jpg",
      "modes": ["driving", "walking"]
    },
    {
      "id": 4,
      "name": "Bruno Klein",
      "image_url": null,
      "modes": ["driving"]
    }
  ]
}
```

- `image_url`: `null` when the driver has no stored image (frontend shows a placeholder).
- `modes`: each driver's full supported set (labels), not filtered to the query mode.
- Empty match → `{ "data": [] }` (200, not 404).

**422 Unprocessable** — `mode` missing or not a valid mode:

```json
{ "message": "The selected mode is invalid.", "errors": { "mode": ["..."] } }
```

**401 Unauthorized** — not authenticated.

### Guarantees

- Only drivers supporting `mode` appear; no driver lacking it appears (FR-003, SC-001).
- Deterministic alphabetical order by `name` (R5, FR — ordering).
- No N+1: `deliveryModes` eager-loaded.

## UI contract — `DriverList` (results page)

Rendered inside `ResultSummary`, in the region the stop list occupied on the edit page (FR-004).

- **Loading**: a neutral loading state (no layout jump).
- **Ready, ≥1 driver**: one row per driver:
  - profile image (rounded), or a placeholder profile icon when `imageUrl` is null (FR-008);
  - **name** as the most prominent element (FR-005);
  - beneath the name, one icon per supported mode — walking→person/footprints, driving→car,
    trucking→truck — and no icon for unsupported modes (FR-006).
  - Rows preserve the API's alphabetical order.
- **Ready, 0 drivers**: the single message **"No one available for this delivery."** in place of the list
  (FR-007, SC-003), styled as the existing muted empty-state.
- **Error**: an inline error/retry line (never a silent blank); the server logs the failure on its side.
- **No time-related info** is shown (FR-010).
- Styling: role-named color variables + shared primitives only (constitution VI); mirrors `stop-list.tsx`.
