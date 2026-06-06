# Implementation Plan: Delivery Route Optimization

**Branch**: `001-delivery-route-optimization` | **Date**: 2026-06-02 | **Spec**: [spec.md](spec.md)

## Summary

Provide an asynchronous, cache-backed route-optimization flow using Laravel backend, React frontend, PostgreSQL, Redis (cache/queue), and WebSockets. The backend never blocks on external API calls: requests return 202 with a Job UUID when work is queued; results are stored in Redis for 24 hours and broadcast to the authenticated user's private channel `private-user.{id}`.

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 10)

**Frontend**: React (Vite or existing React Starter Kit)

**Primary Dependencies**: Laravel queue (Redis), Laravel Echo + **Laravel Reverb** (self-hosted WebSocket server), Guzzle HTTP client, hashed cache keys (Laravel Redis cache), rate limiting middleware, PHPUnit, Pest (optional)

**Storage**: PostgreSQL for app data; Redis for cache and queue; results cached with a 24-hour TTL

**Testing**: PHPUnit / Pest for backend tests; Jest/React Testing Library for frontend

**Target Platform**: Linux or Docker-based development; Windows developer environments supported

**Performance Goals**: Respond to requests within 200ms when cache hit; queue-based processing for cache miss; support at least 10 requests/min per user (rate-limited)

**Routing API**: `https://maps.open-street.com/api/tsp/` — query params: `pts=lat,lng|lat,lng|...` (pipe-separated coordinate pairs), `nb=N` (must equal point count), `mode=driving`, `unit=m`, `tour=closed` (route returns to start), `key=OPENSTREET_API_KEY`. Base URL and key configured via `.env`.

**TSP API Response Schema** (VERIFIED against live API 2026-06-03 — earlier `status`/`route[]` guess was wrong):
```json
{
  "DIMENSION": 4,
  "TOUR": "closed",
  "COMPUTE_TIME": 0.011,
  "TOTAL_TIME": 0.145,
  "OPTIMIZATION": [0, 1, 2, 3],
  "STEPS_DURATIONS": { "TOTAL": 49261, "0": 17910, "1": 17100, "2": 8825, "3": 5426 },
  "STEPS_DISTANCES": { "TOTAL": 1143908, "0": 421122, "1": 406284, "2": 201457, "3": 115045 }
}
```
Fields:
- `OPTIMIZATION` — array of **input-coordinate indices** in optimal visit order. The API echoes **no coordinates**; the client resolves each index back to the coordinate the caller sent (`OpenStreetTspClient::mapResponse()`).
- `STEPS_DISTANCES.TOTAL` — total distance in metres; `STEPS_DURATIONS.TOTAL` — total duration in seconds (per-step values, keyed by step index, sum to `TOTAL`).
- `DIMENSION`, `TOUR`, `COMPUTE_TIME`, `TOTAL_TIME` — echo/diagnostics, unused.

Success detection: presence of an `OPTIMIZATION` array. No `status` field exists. Any payload lacking `OPTIMIZATION` → `invalid_response`; HTTP non-2xx → `api_error`; connection failure/timeout → `timeout`.

**Broadcast Payload Schema**:
- Success event (`TourOptimized`): `{ "job_uuid": "...", "data": { "ordered_stops": [{"lat": 0.0, "lng": 0.0, "order": 0}], "total_distance_m": 450000, "total_duration_s": 18000 } }`
- Failure event (`TourOptimizationFailed`): `{ "job_uuid": "...", "error": { "code": "api_error|timeout|invalid_response|job_failed", "message": "..." } }`

**Constraints**: External OpenStreet TSP API can be slow (minutes for large point sets) or unreliable — must be called only from background jobs. `OpenStreetTspClient` uses a split timeout: connect=15s (fail fast on dead host), read=600s (tolerate slow compute), retries=1 (exponential backoff). Timeout layers must stay ordered `read < job $timeout < worker --timeout < retry_after` (see README). API credentials stored in `.env`, never returned to clients.

**Scale/Scope**: Single-route optimization (one vehicle) per request; not multi-vehicle dispatch in v1

## Constitution Check

This plan follows the project constitution: readable code, defensive error handling, measurable performance goals, and automated tests for correctness.

## Project Structure (feature-specific)

- `app/Http/Controllers/TourOptimizationController.php`
- `app/Jobs/OptimizeTourJob.php`
- `app/Services/CoordinateNormalizer.php`
- `app/Services/OpenStreetTspClient.php`
- `app/Services/TourCache.php`
- `routes/api.php` (POST `/api/tour/optimize`, GET `/api/tour/status/{job_uuid}`)
- `resources/js/routes/` (React: `OptimizeTourForm.tsx`, `RouteResult.tsx`)
- `tests/Feature/TourOptimizationTest.php`
- `tests/Feature/TourOptimizationBroadcastTest.php`
- `tests/Unit/CoordinateNormalizerTest.php`

## Flow (detailed)

1. Frontend sends POST `/api/tour/optimize` with array of `[lat, lng]` coordinate pairs and user auth token. Coordinates are entered directly by the user; geocoding is out of scope.
2. Controller validates and calls `CoordinateNormalizer::normalize()` to round and stable-sort coordinates to 5 decimal places.
3. Normalizer returns a canonical list and `sha256` hash used as cache key: `tour:{user_id}:{hash}`.
4. Controller checks Redis cache; if hit, return 200 with cached data.
5. If miss, generate a Job UUID, `OptimizeTourJob::dispatch()` with UUID, user ID, and canonical payload; store a small placeholder in Redis with status 'pending' and short TTL (e.g., 1 hour) to avoid immediate requeues.
6. Return HTTP 202 with `job_uuid` immediately.
7. `OptimizeTourJob` calls `OpenStreetTspClient` using credentials from `.env`; on success store full result in Redis with 24-hour TTL and publish broadcast event on channel `private-user.{id}` with payload `{ job_uuid, data }`.
8. On failure, job stores an error record and broadcasts a failure event `{ job_uuid, error }` to `private-user.{id}`.
9. Frontend subscribes to `private-user.{id}` and filters events by `job_uuid`; also shows immediate 202 UI and optionally poll for cache result (long-poll or GET `/api/tour/status/{job_uuid}`) if WS unavailable.

## Security & Rate Limiting

- Use Laravel BroadcastAuth to ensure `private-user.{id}` channels require auth and match current user ID.
- Rate limit POST `/api/tour/optimize` to 10/min per authenticated user via `ThrottleRequests` middleware and/or custom limiter in `RouteServiceProvider`.
- Validate inputs strictly (coordinate arrays, min 2 points, max N points — default N=10).

## Error Handling & Observability

- On every external API call, catch timeouts, HTTP errors, and unexpected responses; map them to structured `error_code` and `message` for broadcasts.
- Log job execution outcomes, durations, and API latencies to application logs and, if available, tracing (e.g., Sentry, OpenTelemetry).
- Broadcast failures to prevent infinite loading on frontend.

## Environment & Configuration

- `.env` entries: `OPENSTREET_API_URL=https://maps.open-street.com/api/tsp/`, `OPENSTREET_API_KEY`, `REDIS_URL`, `BROADCAST_DRIVER=reverb`, `QUEUE_CONNECTION=redis`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT=8080`.
- Redis key prefixes: `tour:{user_id}:{hash}` for results; `tour:status:{job_uuid}` for pending markers.

## Tasks (high-level)

- Implement API endpoint and validation
- Implement `CoordinateNormalizer` and hashing
- Add Redis cache checks and TTL
- Create `OptimizeTourJob` background worker
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

1. Implement `CoordinateNormalizer` and unit tests
2. Add API endpoint and cache lookup
3. Implement job and external client
4. Implement frontend hooks and broadcasting

---

Generated by speckit.plan on 2026-06-02
