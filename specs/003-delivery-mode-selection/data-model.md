# Data Model: Delivery Mode Selection

This feature adds **one value** — the delivery `mode` — and threads it through existing structures. No
new persistent entities.

## DeliveryMode (backend enum)

`App\Enums\DeliveryMode` — string-backed:

| Case      | Value        | Notes              |
|-----------|--------------|--------------------|
| Trucking  | `trucking`   | **Default**        |
| Driving   | `driving`    |                    |
| Walking   | `walking`    | On-foot deliveries |

- Helper `default(): self` → `Trucking`.
- Used by `Rule::enum(DeliveryMode::class)` in both form requests and as the fallback value
  (`config('services.openstreet.mode')` remains the env-overridable default and MUST equal `trucking`).

## DeliveryMode (front-end mirror)

```ts
export type DeliveryMode = 'trucking' | 'driving' | 'walking';
export const DELIVERY_MODES: ReadonlyArray<{ value: DeliveryMode; label: string }> = [
    { value: 'trucking', label: 'Trucking' },
    { value: 'driving', label: 'Driving' },
    { value: 'walking', label: 'Walking' },
];
```

## Request shapes

- `POST /api/tour/optimize` — body gains optional `mode`:
  `{ coordinates: [[lat,lng],...], mode?: 'trucking'|'driving'|'walking' }` (omitted ⇒ config default).
- `POST /api/tour/geometry` — already `{ stops: [[lat,lng],...], mode?: ... }`; the front now always
  sends `mode`.

Validation (both requests): `mode` is `sometimes` + `Rule::enum(DeliveryMode::class)`; an out-of-set
value ⇒ 422.

## Cache keys (mode becomes a dimension)

| Entry            | Before                              | After                                          |
|------------------|-------------------------------------|------------------------------------------------|
| Tour             | `tour:{hash}`                       | `tour:{mode}:{hash}`                           |
| Active-job lock  | `tour:active:{userId}:{hash}`       | `tour:active:{userId}:{mode}:{hash}`           |
| Job status       | `tour:status:{jobUuid}`             | unchanged (keyed on the unique job id)         |

`{hash}` is still the order-independent sha256 of the **normalized coordinates only**. `{mode}` is the
validated `DeliveryMode` value. Consequence: identical coordinates under different modes are distinct
cached tours and distinct active-job locks (no cross-mode hit, no dedup across modes).

## Job payload

`OptimizeTourJob` constructor gains `public readonly string $mode`, carried alongside `jobUuid`,
`userId`, `coordinatesHash`, `coordinates`. It is:
- passed to `OpenStreetTspClient::optimize($coordinates, $mode)`,
- used in the mode-keyed `TourCache` calls (`releaseActiveJob`, `putTour`),
- included in failure log context.

## Front-end optimize state

`OptimizeState` carries the chosen mode from submit through to the result so geometry stays congruent:

```ts
| { status: 'idle' }
| { status: 'submitting'; mode: DeliveryMode }
| { status: 'pending'; jobUuid: string; mode: DeliveryMode }
| { status: 'done'; result: TourResult; mode: DeliveryMode }
| { status: 'failed'; error: TourError }
```

`useTourGeometry(result, mode)` reads `mode` from the `done` state (the tour's snapshot), not the live
dropdown.
