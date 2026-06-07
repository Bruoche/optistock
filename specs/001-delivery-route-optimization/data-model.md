# Data Model: Delivery Route Optimization — Front-End View Models

**Date**: 2026-06-07 | **Scope**: front-end state shapes (TypeScript). Backend entities documented in plan.md / README.md.

> These are client-side view models, not DB entities. They mirror the backend's HTTP/WS payloads (see `contracts/frontend-ui.md`).

## Stop

A coordinate the planner placed on the map.

```ts
type Stop = {
  id: string;     // client-generated (crypto.randomUUID) for list keys + removal
  lat: number;    // -90..90
  lng: number;    // -180..180
};
```

- Validation: `2 ≤ stops.length ≤ 10` before Optimize is enabled (FR-006, FR-011).
- Removal by `id` (FR-002, FR-010).

## OptimizedStop (from backend `ordered_stops`)

```ts
type OptimizedStop = { lat: number; lng: number; order: number };
```

## TourResult (success payload `data`)

```ts
type TourResult = {
  ordered_stops: OptimizedStop[];
  total_distance_m: number;   // metres
  total_duration_s: number;   // seconds -> formatted for display (FR-014, SC-007)
};
```

## TourError (failure payload `error`)

```ts
type TourError = {
  code: 'api_error' | 'timeout' | 'invalid_response' | 'job_failed';
  message: string;
};
```

## Optimization state machine

```ts
type OptimizeState =
  | { status: 'idle' }
  | { status: 'submitting' }
  | { status: 'pending'; jobUuid: string }
  | { status: 'done'; result: TourResult }
  | { status: 'failed'; error: TourError };
```

Transitions (see plan.md "State machine"):
- `idle → submitting` on Optimize (requires ≥2 stops).
- `submitting → done` (HTTP 200 cache hit, body carries result).
- `submitting → pending` (HTTP 202, body carries `job_uuid`).
- `pending → done` on `.TourOptimized` OR status poll = `done` (match `job_uuid`).
- `pending → failed` on `.TourOptimizationFailed` OR status poll = `failed`.
- `done | failed → idle` on "new optimization" reset (FR-008).

UI bindings:
- `pending` ⇒ stop list greyed/disabled (FR-012) + bottom optimizing bar (FR-013).
- `done` ⇒ button row replaced by total duration; route drawn on map (FR-014); freed list space empty (FR-015).
- `failed` ⇒ `sonner` toast from `error.message`; list re-enabled.

## RouteLayer input (FR-019 boundary)

```ts
type RoutePath = Array<{ lat: number; lng: number }>;  // ordered; straight segments now, road geometry later
```
