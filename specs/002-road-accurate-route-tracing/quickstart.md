# Quickstart: Road-Accurate Route Tracing

**Date**: 2026-06-07 | Builds on 001. No new runtime processes; `/route` is synchronous.

## 1. Env

Add to `.env` + `.env.example` (reuses the existing `OPENSTREET_API_KEY`):
```
OPENSTREET_ROUTE_URL="https://maps.open-street.com/api/route/"
OPENSTREET_ROUTE_TIMEOUT=15
```
`config/services.php` → `services.openstreet.route_url`, `services.openstreet.route_timeout`.

## 2. No new deps

Backend: small `PolylineDecoder` (Google algorithm, no package). Front-end: none — `RouteLayer` already
consumes coordinates (001 FR-019); the backend returns decoded coordinates.

## 3. Run

Same as 001 (serve + reverb + queue worker + vite). `/route` adds no process — it's a normal request
inside `POST /api/tour/route`.

## 4. Manual verification (the two soft assumptions)

After an optimization completes:
1. **Polyline precision** — the road path should overlay real roads. If it's wildly off / scaled wrong,
   switch the decoder from precision 5 → 6 (research.md R1).
2. **Duration unit** — the road-accurate duration should be plausible (a Paris→Lyon leg ≈ a few hours).
   If it looks ~1000× too big, `total_time` is milliseconds, not seconds (research.md R3).

## 5. Smoke test

- ≥3-stop tour: straight lines show first, then snap to roads; duration updates to the road-accurate value.
- 2-point tour: starts "Unavailable", then shows a real duration once `/route` returns.
- Force a leg failure (bad key / kill network mid-fetch): straight lines + initial estimate persist; a
  warning is logged; no blank/broken result.
