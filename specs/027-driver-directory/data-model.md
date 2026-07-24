# Phase 1 Data Model: Drivers Directory

No schema change, no migration. This feature reads existing tables and shapes a light payload.

## Existing entities (read-only, unchanged)

- **Driver** (`drivers`): `id`, `name`, `image_path`, `warehouse_id`. Relations: `warehouse`
  (belongsTo), `deliveryModes` (belongsToMany via `driver_delivery_mode`), `weekDays`, `tours`.
  Accessor `image_url` → public URL or null.
- **Warehouse** (`warehouses`): `id`, `name`, `latitude`, `longitude`.
- **DeliveryMode** (`delivery_modes`): `label` (one of `trucking` | `driving` | `walking`, mirrors
  the `App\Enums\DeliveryMode` enum which owns the allowed set).

## New query scope

`Driver::scopeMatching(Builder $q, ?string $name, array $modes, ?int $warehouseId): Builder`

- **name**: when non-empty after trim → `whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($name).'%'])`.
  Empty/whitespace-only → no clause.
- **modes**: for each mode label in `$modes` → `whereHas('deliveryModes', label = mode)`. Empty → no
  clause. Multiple → AND (driver must possess all).
- **warehouseId**: when non-null → `where('warehouse_id', $warehouseId)`. Null → no clause.
- Always `->with(['deliveryModes', 'warehouse'])->orderByRaw('LOWER(name)')` (case-insensitive
  alphabetical regardless of DB collation — matches the spec's case-insensitive sort; eager-load
  avoids N+1).

## Search criteria (request input → `DriverDirectoryRequest`)

| Field       | Type              | Rules                                                     | Default / empty meaning     |
|-------------|-------------------|-----------------------------------------------------------|-----------------------------|
| `name`      | string, optional  | `nullable`, `string`, `max:255`                           | absent/blank → no name filter |
| `modes`     | array, optional   | `nullable`, `array`; `modes.*` `Rule::enum(DeliveryMode)` | absent/empty → all modes     |
| `warehouse` | integer, optional | `nullable`, `integer`, `exists:warehouses,id`             | absent → any warehouse       |

Validation failure → 422 (unknown warehouse / invalid mode never silently ignored). Access requires
an authenticated user (route `auth` middleware); drivers are global (no ownership check).

## Output row — `DirectoryDriverData::toArray()`

One entry per matching driver, in name order. Deliberately **excludes** workday/road/break figures
(those belong to the assignment context, per spec Assumptions).

```jsonc
{
  "id": 42,
  "name": "Sacha Brook",
  "image_url": "https://…/storage/drivers/42.jpg", // or null → placeholder
  "modes": ["trucking", "driving"],                // labels; drives the mode icons
  "warehouse_id": 3,
  "warehouse_name": "North Depot"
}
```

Endpoint response: `{ "data": DirectoryDriverData[] }` (same envelope as `available` / `day`).

## Frontend view model — `DirectoryDriver` (types/driver.ts)

```ts
export type DirectoryDriver = {
  id: number;
  name: string;
  imageUrl: string | null;
  modes: DeliveryMode[];
  warehouseId: number;
  warehouseName: string;
};
```

Reuses the existing `WarehouseOption` (`{ id, name }`) for the warehouse selector and `DeliveryMode`
/ `DELIVERY_MODES` for the modes selector. The page holds criteria state
`{ name: string; modes: DeliveryMode[]; warehouseId: number | null }`; the hook maps the API row
(`image_url` → `imageUrl`, `warehouse_id`/`warehouse_name` → `warehouseId`/`warehouseName`).

## State / transitions

Stateless read. UI states: `loading` (spinner) → `ready` (list, or the exact empty-state text
`no drivers found with current criterias.` when zero rows) or `error` (retrievable message, never a
silent empty list). Any criterion change → debounced/cancelling re-fetch that always resolves to the
latest criteria.
