# Data Model: Delivery Driver Assignment

## Tables

### `drivers`

| Column      | Type              | Notes                                              |
| ----------- | ----------------- | -------------------------------------------------- |
| id          | bigint, PK, auto  |                                                    |
| name        | string            | Driver's display name (required).                  |
| image_path  | string, nullable  | Path on the `public` disk; null → placeholder.     |
| created_at  | timestamp         |                                                    |
| updated_at  | timestamp         |                                                    |

- Model `App\Models\Driver`.
- Accessor `image_url`: `image_path ? Storage::disk('public')->url(image_path) : null`.
- Relation: `deliveryModes(): belongsToMany(DeliveryMode::class)`.
- Scope `available(DeliveryMode $mode)`: `whereHas('deliveryModes', label = $mode->value)`, eager-loads
  `deliveryModes`, ordered by `name` asc.

### `delivery_modes` (shared lookup / "enum table")

| Column | Type             | Notes                                                       |
| ------ | ---------------- | ----------------------------------------------------------- |
| id     | bigint, PK, auto |                                                             |
| label  | string, unique   | One of `trucking` / `driving` / `walking` (enum values).    |

- Model `App\Models\DeliveryMode` (the `App\Enums\DeliveryMode` enum is imported aliased as
  `DeliveryModeEnum` where both are referenced).
- Seeded by `DeliveryModeSeeder` with exactly the three `App\Enums\DeliveryMode` backing values (idempotent
  `updateOrCreate` on `label`).
- Relation: `drivers(): belongsToMany(Driver::class)`.
- No timestamps needed (static reference data); `$timestamps = false`.

### `driver_delivery_mode` (pivot)

| Column            | Type                          | Notes                                  |
| ----------------- | ----------------------------- | -------------------------------------- |
| id                | bigint, PK, auto              |                                        |
| driver_id         | FK → drivers.id, cascadeOnDelete       |                               |
| delivery_mode_id  | FK → delivery_modes.id, cascadeOnDelete |                              |

- Unique composite `(driver_id, delivery_mode_id)` — a driver links a mode at most once.

## Constraints / business rules

- **CR-1**: A driver MUST support at least one mode and MUST NOT support all three (1 or 2 of 3). Enforced at
  data-creation time (factory/seeder + any future create path), not by a DB constraint (the pivot cannot
  express "1–2 of 3" alone). Tests assert the rule on seeded/factory data.
- **CR-2**: `delivery_modes.label` values MUST equal the `App\Enums\DeliveryMode` backing values so the
  frontend filters with the same strings. A unit test guards label ↔ enum parity.
- **CR-3**: "Available for a tour" = the driver's `deliveryModes` includes the tour's mode (label match).

## Entities (frontend)

```ts
// resources/js/types/tour.ts
export type Driver = {
    id: number;
    name: string;
    imageUrl: string | null;        // from API image_url
    modes: DeliveryMode[];          // labels, subset of DELIVERY_MODES
};
```

## Relationships

```
Driver  *────*  DeliveryMode (model)      (belongsToMany via driver_delivery_mode)
Tour (transient, client-side) ── has one DeliveryMode ── filters Drivers by matching mode
```

No persisted Tour↔Driver link this feature (assignment is out of scope).

## Seed / fixture data

- `DeliveryModeSeeder`: 3 rows — `trucking`, `driving`, `walking`.
- `DriverFactory`: name (faker), optional `image_path`, attaches a random valid mode set (1–2 of 3) after
  create. Used by tests and optional demo seeding.
