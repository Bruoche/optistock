# Data Model: Per-Stop Delivery Duration & Tour Duration Total

No database tables, migrations, or persisted entities. Durations are transient request/UI data; `wait_time`
is a derived response value. This feature touches only the request payload, a response field, and frontend
view models.

## Transient / request entities

### Stop (client-side, extended)

```ts
// resources/js/types/tour.ts
export type Stop = {
    id: string;             // client-generated (unchanged)
    lat: number;            // unchanged
    lng: number;            // unchanged
    durationMinutes: number; // NEW — minutes spent delivering at this stop; default 10
};
```

- **Default**: `durationMinutes = 10` is assigned in `addStop` for every new stop.
- **Validation (client)**: kept a non-negative whole number; empty/invalid input coerces to a valid value
  (see CR-2). Integer minutes only.

### Optimize request `durations` (new field)

`POST /api/tour/optimize` gains an optional `durations` array, parallel to `coordinates`:

| Field          | Type                | Rules                                                              |
| -------------- | ------------------- | ------------------------------------------------------------------ |
| `durations`    | array of int        | optional; when present, `size` == `coordinates` size               |
| `durations.*`  | int (minutes)       | `integer`, `min:0`, `max:1440`                                     |

- When `durations` is **omitted**, the server defaults every stop to **10** minutes.
- Durations are **not** forwarded to the OpenStreet API and are **not** part of the optimize cache key.

## Derived value

### `wait_time_s` (response field)

- `wait_time_s = sum(durations_in_minutes) * 60` (seconds).
- Computed in `TourOptimizationController` from the request `durations` on **every** call (cache hit or miss).
- Returned as a sibling of `data` (200) / `job_uuid` (202); never nested in the cached tour body.

### `tourDurationS` (frontend, displayed)

- `tourDurationS = (deliveryS ?? 0) + waitTimeS`
- `deliveryS = roadMetrics?.duration_s ?? result.total_duration_s` (the existing value, now labeled
  **Time on road**); a null `deliveryS` contributes 0 to the tour duration.

## Frontend view-model changes

```ts
// OptimizeState carries waitTimeS alongside mode/loop (snapshotted at request time):
type OptimizeState =
    | { status: 'idle' }
    | { status: 'submitting'; mode: DeliveryMode; loop: boolean; waitTimeS: number }
    | { status: 'pending'; jobUuid: string; mode: DeliveryMode; loop: boolean; waitTimeS: number }
    | { status: 'done'; result: TourResult; mode: DeliveryMode; loop: boolean; waitTimeS: number }
    | { status: 'failed'; error: TourError };
```

`TourResult` is **unchanged** — `wait_time_s` rides as a response sibling and is carried in `OptimizeState`,
not embedded in the (cacheable) tour body.

## Constraints / business rules

- **CR-1**: `wait_time_s` MUST equal the exact sum of the submitted stop durations (in seconds), independent
  of optimized stop order. Tested for cache-miss and cache-hit paths.
- **CR-2**: A stop duration MUST be a non-negative whole number of minutes (0–1440); empty/non-numeric/negative
  client input coerces to **0** (non-integers floored), and the server returns `422` on out-of-rule payloads,
  so the totals can never become `NaN` or negative.
- **CR-3**: `Tour duration` MUST equal `Time on road` + `wait_time` for every tour, treating an unavailable
  `Time on road` as 0.
- **CR-4**: Editing a stop's duration MUST NOT change the optimize cache key (no re-fire of the upstream TSP
  call for an otherwise identical route).
