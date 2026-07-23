# Contract: `GET /api/drivers` — Drivers Directory

**New endpoint. Additive only — no existing route, controller method, request, service, or payload
is modified.** Registered in the existing `routes/api.php` `auth` group with `throttle:tour-read`,
route name `drivers.index`.

## Request

`GET /api/drivers?name=<partial>&modes[]=<mode>&modes[]=<mode>&warehouse=<id>`

Headers: `Accept: application/json`; session cookie (same-origin), same auth as the Inertia app.

Query parameters (all optional; any omitted → no restriction from that criterion):

| Param       | Type            | Rules                                              | Semantics                                                    |
|-------------|-----------------|----------------------------------------------------|-------------------------------------------------------------|
| `name`      | string          | `nullable string max:255`                          | partial, case-insensitive contains; blank/whitespace → all  |
| `modes`     | string[]        | `nullable array`, each `Rule::enum(DeliveryMode)`  | AND — driver must possess **every** listed mode             |
| `warehouse` | integer         | `nullable integer exists:warehouses,id`            | driver's `warehouse_id` must equal it                       |

The default request (no params) returns **all** drivers, name-sorted.

## Responses

### 200 OK

```json
{
  "data": [
    {
      "id": 42,
      "name": "Sacha Brook",
      "image_url": "https://app.test/storage/drivers/42.jpg",
      "modes": ["trucking", "driving"],
      "warehouse_id": 3,
      "warehouse_name": "North Depot"
    }
  ]
}
```

- `data` is ordered alphabetically by `name` (case-insensitive), and contains **only** the drivers
  matching all supplied criteria.
- `image_url` is `null` when the driver has no picture (frontend shows the neutral placeholder).
- `modes` is the driver's full label list (drives the row's mode icons), regardless of the `modes`
  filter.
- Zero matches → `{ "data": [] }` with 200 (the frontend renders the exact empty-state text). An
  empty array is **not** an error.

### 422 Unprocessable Entity

Standard Laravel validation error body when `warehouse` is not an existing id, or a `modes[]` value
is not a valid delivery mode. Malformed criteria are rejected, never silently dropped.

### 401 / 419

Unauthenticated (or CSRF/session-expired) — same as every other `/api` route in the group.

## Guarantees

- **No routing, no workday projection, no break/road figures** — this is a pure directory read
  (distinct from `GET /api/tour/drivers`, which stays frozen and untouched).
- **No N+1** — `deliveryModes` and `warehouse` are eager-loaded.
- Read-only; idempotent; safe to call on every debounced criteria change.

## Web page route (supporting)

`GET /driver` (singular — coherent with the existing `tour` / `driver/{driver}` routes, distinct
from the `/driver/{driver}` management page) → `DriverPageController::directory` (Inertia
`driver/directory`), `auth`+`verified`, new route name `driver.directory.page`. Props: `{ warehouses: { id, name }[] }` (name-ordered), the
same shape `manage()` already ships. `manage()` is unchanged. The modes options come from the
frontend `DELIVERY_MODES` constant. Each row links to `driver.manage.page` (`/driver/{id}`).
