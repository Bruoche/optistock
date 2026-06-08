# Data Model: Tour Loop Toggle

This feature adds **one value** — the `loop` boolean — threaded through existing structures. No new
persistent entities.

## Loop Preference

- Domain concept: whether a tour returns to its origin. `loop = true` → **closed** (default);
  `loop = false` → **open** (one-way, no return).
- Represented as a **boolean** end-to-end (HTTP request, cache, front state); mapped to the TSP `tour`
  string (`closed`|`open`) in exactly one place: the **job** (`OptimizeTourJob`), which passes the string
  to the thin `OpenStreetTspClient`.
- Front mirror: a `boolean` carried on `OptimizeState`.

## Request shapes

- `POST /api/tour/optimize` — body gains optional `loop`:
  `{ coordinates: [[lat,lng],...], mode?: 'trucking'|'driving'|'walking', loop?: boolean }`
  (omitted ⇒ `true`).
- `POST /api/tour/geometry` — body gains optional `loop`:
  `{ stops: [[lat,lng],...], mode?: ..., loop?: boolean }` (omitted ⇒ `true`). The front always sends it.

Validation (both requests): `loop` is `sometimes` + `boolean`; a non-boolean ⇒ 422.

## Cache keys (shape becomes a dimension, on top of 003's mode)

| Entry            | 003 (before)                          | 004 (after)                                          |
|------------------|---------------------------------------|------------------------------------------------------|
| Tour             | `tour:{mode}:{hash}`                   | `tour:{mode}:{shape}:{hash}`                          |
| Active-job lock  | `tour:active:{userId}:{mode}:{hash}`   | `tour:active:{userId}:{mode}:{shape}:{hash}`          |
| Job status       | `tour:status:{jobUuid}`               | unchanged (keyed on the unique job id)               |

`{shape}` is the `closed`|`open` label the cache key builder derives from the `loop` bool callers pass
(the cache stores/keys on the boolean; this string is only the key label). `{hash}` is still the order-independent sha256 of the
normalized coordinates only. Consequence: same coordinates + same mode but different shape ⇒ distinct
cached tours and distinct active-job locks (no cross-shape hit, no dedup across shapes).

## Job payload

`OptimizeTourJob` constructor gains `public readonly bool $loop`, alongside `jobUuid`, `userId`,
`coordinatesHash`, `coordinates`, `mode`. It is:
- translated in the job to `tour = loop ? 'closed' : 'open'` and passed to
  `OpenStreetTspClient::optimize($coordinates, $mode, $tour)` (the client just forwards the string),
- passed as the **boolean** to the shape-keyed `TourCache` calls (`releaseActiveJob`, `putTour`),
- included in failure log context.

## Geometry trace

`TourGeometryService::trace(array $orderedStops, ?string $mode, bool $loop = true)`:
- looped: iterate consecutive legs **including** the closing leg `(last → first)` (current behaviour);
- open: iterate legs `0..count-2` only; the route ends at the last stop; totals exclude the return leg.

## Front-end optimize state

`OptimizeState` carries `loop` from submit through to the result so the drawn route stays congruent:

```ts
| { status: 'idle' }
| { status: 'submitting'; mode: DeliveryMode; loop: boolean }
| { status: 'pending'; jobUuid: string; mode: DeliveryMode; loop: boolean }
| { status: 'done'; result: TourResult; mode: DeliveryMode; loop: boolean }
| { status: 'failed'; error: TourError }
```

`useTourGeometry(result, mode, loop)` reads `loop` from the `done` state (the tour's snapshot), and
`composeGeometry` sets the `RouteLayer` `closed` flag = `loop`.
