# Implementation Plan: Delivery Route Optimization

**Branch**: `001-delivery-route-optimization` | **Date**: 2026-06-02 | **Spec**: [spec.md](spec.md)

## Summary

Provide an asynchronous, cache-backed route-optimization flow using Laravel backend, React frontend, PostgreSQL, Redis (cache/queue), and WebSockets. The backend never blocks on external API calls: requests return 202 with a Job UUID when work is queued; results are stored in Redis for 24 hours and broadcast to the authenticated user's private channel `private-user.{id}`.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 10)

**Frontend**: React (Vite or existing React Starter Kit)

**Primary Dependencies**: Laravel queue (Redis), Laravel Echo + Pusher/Socket.io or Laravel WebSockets, Guzzle HTTP client, hashed cache keys (Laravel Redis cache), rate limiting middleware, PHPUnit, Pest (optional)

**Storage**: PostgreSQL for app data; Redis for cache and queue; results cached with a 24-hour TTL

**Testing**: PHPUnit / Pest for backend tests; Jest/React Testing Library for frontend

**Target Platform**: Linux or Docker-based development; Windows developer environments supported

**Performance Goals**: Respond to requests within 200ms when cache hit; queue-based processing for cache miss; support at least 10 requests/min per user (rate-limited)

**Routing API**: OSRM Trip Service (`/trip/v1/driving/{coordinates}`) for TSP route optimization via nearest-neighbour heuristic; fallback option: Valhalla `/optimized_route` if OSRM Trip is unavailable. Endpoint configured via `OPENSTREET_API_URL` in `.env`.

**Constraints**: External OpenStreet TSP API can be slow or unreliable - must be called only from background jobs; API credentials stored in `.env` and never returned to clients

**Scale/Scope**: Single-route optimization (one vehicle) per request; not multi-vehicle dispatch in v1

## Constitution Check

This plan follows the project constitution: readable code, defensive error handling, measurable performance goals, and automated tests for correctness.

## Project Structure (feature-specific)

- `app/Http/Controllers/RouteOptimizationController.php`
- `app/Http/Controllers/GeocodeController.php`
- `app/Jobs/OptimizeRouteJob.php`
- `app/Services/RouteNormalizer.php`
- `app/Services/OpenStreetTspClient.php`
- `app/Services/GeocodingService.php`
- `app/Services/RouteCache.php`
- `routes/api.php` (POST `/api/route/optimize`, GET `/api/route/result/{job_uuid}`, POST `/api/route/validate`)
- `resources/js/routes/` (React: `OptimizeRouteForm.tsx`, `RouteSelector.tsx`, `RouteResult.tsx`)
- `tests/Feature/RouteOptimizationTest.php`
- `tests/Feature/RouteOptimizationBroadcastTest.php`
- `tests/Unit/RouteNormalizerTest.php`

## Flow (detailed)

1. Frontend sends POST `/api/route/optimize` with array of `[lat, lng]` coordinate pairs and user auth token. In Phase 3 MVP, coordinates are entered directly; in Phase 4 they are resolved from address text via `GeocodingService` before submission.
2. Controller validates and calls `RouteNormalizer::normalize()` to round and stable-sort coordinates to 5 decimal places.
3. Normalizer returns a canonical list and `sha256` hash used as cache key: `route:opt:{user_id}:{hash}`.
4. Controller checks Redis cache; if hit, return 200 with cached data.
5. If miss, generate a Job UUID, `OptimizeRouteJob::dispatch()` with UUID, user ID, and canonical payload; store a small placeholder in Redis with status 'pending' and short TTL (e.g., 1 hour) to avoid immediate requeues.
6. Return HTTP 202 with `job_uuid` immediately.
7. `OptimizeRouteJob` calls `OpenStreetTspClient` using credentials from `.env`; on success store full result in Redis with 24-hour TTL and publish broadcast event on channel `private-user.{id}` with payload `{ job_uuid, data }`.
8. On failure, job stores an error record and broadcasts a failure event `{ job_uuid, error }` to `private-user.{id}`.
9. Frontend subscribes to `private-user.{id}` and filters events by `job_uuid`; also shows immediate 202 UI and optionally poll for cache result (long-poll or GET `/api/route/result/{job_uuid}`) if WS unavailable.

## Security & Rate Limiting

- Use Laravel BroadcastAuth to ensure `private-user.{id}` channels require auth and match current user ID.
- Rate limit POST `/api/route/optimize` to 10/min per authenticated user via `ThrottleRequests` middleware and/or custom limiter in `RouteServiceProvider`.
- Validate inputs strictly (coordinate arrays, min 2 points, max N points — default N=10).

## Error Handling & Observability

- On every external API call, catch timeouts, HTTP errors, and unexpected responses; map them to structured `error_code` and `message` for broadcasts.
- Log job execution outcomes, durations, and API latencies to application logs and, if available, tracing (e.g., Sentry, OpenTelemetry).
- Broadcast failures to prevent infinite loading on frontend.

## Environment & Configuration

- `.env` entries: `OPENSTREET_API_URL`, `OPENSTREET_API_KEY`, `REDIS_URL`, `BROADCAST_DRIVER`, `QUEUE_CONNECTION=redis`.
- Redis key prefixes: `route:opt:{user_id}:{hash}` for results; `route:opt:pending:{job_uuid}` for pending markers.

## Tasks (high-level)

- Implement API endpoint and validation
- Implement `RouteNormalizer` and hashing
- Add Redis cache checks and TTL
- Create `OptimizeRouteJob` background worker
- Implement `OpenStreetTspClient` with robust retries and timeouts
- Integrate broadcasting on job success/failure
- Add frontend components and WS handling
- Add rate limiting and request quotas
- Add tests (unit and feature) and README

## Acceptance Criteria

- Cache hit returns result within 200ms
- Cache miss returns HTTP 202 and job UUID immediately
- Successful jobs result in Redis storage with 24h TTL and WS broadcast
- Failure jobs broadcast an error event so frontend stops waiting
- Rate limiter enforces 10 per minute per user

## Next Steps

1. Implement `RouteNormalizer` and unit tests
2. Add API endpoint and cache lookup
3. Implement job and external client
4. Implement frontend hooks and broadcasting

---

Generated by speckit.plan on 2026-06-02
