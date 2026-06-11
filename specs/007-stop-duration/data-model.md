# Data Model: Per-Stop Delivery Duration & Tour Duration Total

No database tables, migrations, or persisted entities. No request or response payload changes either —
durations are **transient client UI state** and the stop total is **derived on the frontend**. This feature
touches only frontend view models.

## Transient / client entities

### Stop (client-side, extended)

```ts
// resources/js/types/tour.ts
export const DEFAULT_STOP_DURATION_MINUTES = 10;
export const MAX_STOP_DURATION_MINUTES = 1440; // 24 h ceiling, blocks absurd/overflow input

export type Stop = {
    id: string;              // client-generated (unchanged)
    lat: number;             // unchanged
    lng: number;             // unchanged
    durationMinutes: number; // NEW — minutes spent delivering at this stop; default 10
};
```

- **Default**: `durationMinutes = DEFAULT_STOP_DURATION_MINUTES` is assigned in `addStop` for every new stop.
- **Validation (client)**: kept a non-negative whole number in `0..MAX_STOP_DURATION_MINUTES`; empty/invalid
  input coerces to a valid value (see CR-2). Integer minutes only.

### Optimize request / response — UNCHANGED

`POST /api/tour/optimize` gains **no** `durations` request field and returns **no** `wait_time_s` response
field. The job, `TourOptimized` broadcast, status endpoint, and `TourCache` are likewise unchanged. Durations
never reach the backend.

## Derived values (frontend)

### `waitTimeS` (derived in the hook)

- `waitTimeS = Σ(stop.durationMinutes) * 60` (seconds), computed from `useTourOptimization`'s `stops`.
- Exposed from the hook; recomputed on render. Not stored in `OptimizeState`, not snapshotted (stops are frozen
  between submit and `done` — see research R2).

### `tourDurationS` (displayed in `ResultSummary`)

- `tourDurationS = (deliveryS ?? 0) + waitTimeS`
- `deliveryS = roadMetrics?.duration_s ?? result.total_duration_s` (the existing value, now labeled
  **Time on road**); a null `deliveryS` contributes 0 to the tour duration.

## Frontend view-model changes

`OptimizeState` is **unchanged** — `waitTimeS` is derived from `stops`, not carried through the state machine:

```ts
type OptimizeState =
    | { status: 'idle' }
    | { status: 'submitting'; mode: DeliveryMode; loop: boolean }
    | { status: 'pending'; jobUuid: string; mode: DeliveryMode; loop: boolean }
    | { status: 'done'; result: TourResult; mode: DeliveryMode; loop: boolean }
    | { status: 'failed'; error: TourError };
```

`TourResult` is **unchanged** — the stop total is a client-derived display value, never part of the (cacheable)
tour body.

## Constraints / business rules

- **CR-1**: `waitTimeS` MUST equal the exact sum of the stops' durations (in seconds), independent of the
  optimized stop order.
- **CR-2**: A stop duration MUST be a non-negative whole number of minutes (0–1440); empty/non-numeric/negative
  client input coerces to **0** (non-integers floored, values > 1440 clamped to 1440), so the totals can never
  become `NaN` or negative.
- **CR-3**: `Tour duration` MUST equal `Time on road` + `waitTimeS` for every tour, treating an unavailable
  `Time on road` as 0.
