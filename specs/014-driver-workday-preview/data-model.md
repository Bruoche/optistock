# Data Model — Driver Workday Preview (014)

No database changes. This feature is a payload + presentation layer over the 013 schema
(`warehouses`, `driver_tour` start/end coordinates + `sequence`, persisted `tours`/`stops`).

## Backend value objects & services

### `WorkdayLeg` (new value object, `app/Services/WorkdayLeg.php`)

One drawable piece of a driver's projected workday, in chain order.

| Field | Type | Meaning |
|---|---|---|
| `kind` | `'connection'` \| `'tour'` | Connection drive vs an already-assigned tour |
| `dotted` | `bool` | Render flag: `true` for connections, `false` for tours |
| `path` | `list<array{0: float, 1: float}>` | Straight fallback points, `[lat, lng]`: `[from, to]` for connections; rotated ordered stop coordinates for tours |
| `geometry` | `list<array{0: float, 1: float}>` \| `null` | Decoded road coordinates when already fetched (connections); `null` when the front must trace lazily (prior tours) |
| `loop` | `bool` | `true` only for a looping tour leg — passed to the trace request so the return arc is traced |

Serialized as snake_case JSON in the drivers payload (see `contracts/driver-workday.md`).

### `WorkdayLegsBuilder` (new service, `app/Services/WorkdayLegsBuilder.php`)

`build(Coordinate $warehouse, list<PriorTourLeg> $priorTours, Coordinate $candidateStart, Coordinate $candidateEnd, ?string $mode): list<WorkdayLeg>`

- Emits, in order: connection(warehouse → first start), then per prior tour
  [tour leg, connection(end → next start)], …, connection(last prior end → candidate start),
  connection(candidate end → warehouse). The candidate tour itself is **not** a leg (R4).
- Connection geometry/duration read from `TravelTimeService` (already preloaded — no new
  routing calls). Unroutable connection → `geometry: null` (front keeps the straight line).
- Tour-leg path rotation (R3): looping tour entered at stop *k* → stops rotated to start at
  *k*, `loop: true`; one-way → position order, reversed when the recorded start is the last
  stop, `loop: false`. Pivot-start ↔ stop matching uses `Coordinate::isSameAs`; no match →
  unrotated order + `warning` log.

### `PriorTourLeg` (new value object) — builder input

| Field | Type | Source |
|---|---|---|
| `start`, `end` | `Coordinate` | `driver_tour` pivot |
| `loop` | `bool` | `tours.loop` |
| `stopCoordinates` | `list<Coordinate>` | `stops` ordered by `position` (one grouped query for all prior tours) |

`DriverController::priorSegmentsByDriver` grows into supplying both the 013 `TourSegment`s
(estimator input, unchanged) and these richer rows (builder input) from one query set.

### `TravelTimeService` (changed)

Cache entry widens from `?int` to `array{duration_s: ?int, coordinates: ?list<array{0: float, 1: float}>}`:

- `durationBetween(...)` — unchanged contract (0 coincident, null unroutable).
- `geometryBetween(from, to, mode): ?list<array{0: float, 1: float}>` — **new**; null when
  unroutable or coincident (nothing to draw).
- `fetchBatch` stores both fields from one parse.

### `OpenStreetRouteClient` (changed)

- `legFromResponse(Response): ?array{coordinates, distance_m, duration_s}` — **new**,
  non-throwing pooled-path parser sharing `mapToLeg`'s logic; replaces
  `durationFromResponse` (its only caller is `TravelTimeService`).

## Frontend types (`resources/js/types/tour.ts`)

```ts
/** One black piece of a driver's projected workday (feature 014). */
export type WorkdayLeg = {
    kind: 'connection' | 'tour';
    dotted: boolean;
    path: Array<[number, number]>;          // [lat, lng] straight fallback
    geometry: Array<[number, number]> | null; // decoded road path, or null → trace lazily
    loop: boolean;                           // trace flag for looping tour legs
};

// Driver gains:
legs: WorkdayLeg[];
```

## Frontend state

- **Selection** — `selectedDriver: Driver | null` in `pages/tour/optimize.tsx` (R7). Toggled
  by row click; cleared on reset / date change / driver-list reload; gates the
  "Assign Driver" button and the map overlay.
- **Preview geometry** — `useWorkdayPreview` ref cache: `Map<string, Array<[number, number]>>`
  keyed by `driverId:legIndex`, cleared when the driver list reloads. Applied to state only
  when the response's driver is still selected (R8).

## Invariants

- `legs` order is the exact chain order of the projected day; the candidate tour slots
  between the second-to-last and last connection.
- A connection leg with coincident endpoints has `path: [p, p]` and `geometry: null`
  (genuine zero — nothing drawn, consistent with 013 FR-010).
- Preview traces never carry `tour_id` → never mutate persisted tour totals (R8).
- `legs` adds to the drivers payload; every 013 field (`projected_seconds`,
  `projected_incomplete`, `start_index`, `warehouse_name`) is unchanged.
