# Contract: Driver detail update

## `PATCH /api/driver/{driver}` (api, multipart/form-data)

Auth: `auth`. Throttle: `tour-read`. Unknown driver → 404.

### Request (multipart)
| Field | Rules |
|---|---|
| `name` | required, string, 1..255 |
| `warehouse_id` | required, integer, `exists:warehouses,id` |
| `modes[]` | required, array min 1; each `in:trucking,driving,walking` (validated against the `DeliveryMode` enum). The selector's options come from the frontend `DELIVERY_MODES` constant, not a server prop. |
| `image` | optional, `image`, max size per app default; stored on `public` disk |
| `_method` | `PATCH` (Laravel multipart method spoof) |

### Responses
- **200** `{ data: { id, name, image_url, modes, warehouse_id, warehouse_name, warehouse_coordinate } }` — saved values (the identity bar resets its baseline to these, Update returns disabled).
- **422** validation error, standard Laravel `{ message, errors }`. Empty name or zero modes land here; stored values unchanged (FR-007). Field named in `errors`.
- Network/5xx → client keeps edits on screen, Update stays enabled (FR-009).

### Behaviour
- `modes` applied via `deliveryModes()->sync(...)`.
- New `image` replaces `image_path` (old file cleanup best-effort); absent `image` keeps current.
- Existing assignments are **not** touched (FR-007b). Removing a mode the driver has assigned tours in is allowed.

### Client gate (FR-007a)
- The Update button is enabled only when any field differs from the loaded baseline (dirty-check).
- If `warehouse_id` differs from baseline, a fixed `ConfirmDialog` advisory ("Changing the warehouse may affect this driver's existing assignments.") is shown before the request; cancel aborts, continue submits. No per-date enumeration.
