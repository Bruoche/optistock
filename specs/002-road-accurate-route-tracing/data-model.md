# Data Model: Road-Accurate Route Tracing

**Date**: 2026-06-07 | Backend payloads + front-end view models. Builds on 001's `TourResult`.

## Upstream `/route` response (one leg)

```
{
  "polyline": string,        // Google encoded polyline (precision 5 assumed)
  "total_distance": number,  // metres
  "total_time": number,      // seconds (assumed)
  "status": int | string     // 0 / "OK" = success; else failure
}
```

## Backend internal — per leg (after decode + map)

```php
// LegGeometry
[
  'ok' => bool,
  'coordinates' => array<int, array{0: float, 1: float}>, // [[lat,lng], ...] decoded; [] if !ok
  'distance_m' => int|null,   // null if !ok
  'duration_s' => int|null,   // null if !ok
]
```

## Our endpoint response — whole tour

```
{
  "legs": [
    { "ok": true,  "coordinates": [[lat,lng], ...], "distance_m": int, "duration_s": int },
    { "ok": false }
  ],
  "total_distance_m": int | null,   // compounded; null if any leg failed (FR-008)
  "total_duration_s": int | null    // compounded; null if any leg failed (FR-008)
}
```

- `legs` are in visit order, one per consecutive pair of the closed tour (last→first included).
- Totals are the sum across legs **only when every leg succeeded**; otherwise `null` and the front
  keeps the initial 001 estimate (FR-005/FR-008).

## Front-end view models (TypeScript — add to `resources/js/types/tour.ts`)

```ts
export type LegGeometry =
  | { ok: true; coordinates: Array<{ lat: number; lng: number }>; distance_m: number; duration_s: number }
  | { ok: false };

export type TourGeometry = {
  legs: LegGeometry[];
  total_distance_m: number | null;
  total_duration_s: number | null;
};
```

## How the front composes the drawn path (per FR-006)

- For each leg `i` (stop `i` → stop `i+1`, wrapping last→first):
  - if `legs[i].ok` → use its road `coordinates`,
  - else → straight segment `[stop_i, stop_{i+1}]` (fallback).
- Concatenate into the single `RoutePath` fed to `RouteLayer` (001 FR-019 boundary; interface unchanged).

## Metrics display (per FR-003/FR-004/FR-008)

- Initial: 001's `TourResult.total_duration_s` (or "Unavailable" for 2-point).
- After geometry: if `TourGeometry.total_duration_s != null` → replace with it; else keep the initial.

## Stale guard (FR-010)

- The geometry fetch is tied to the current result via a result-identity token (bumped on new
  optimization / reset); a response for a superseded result (new
  optimization or reset) is discarded.
