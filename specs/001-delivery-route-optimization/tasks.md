# Tasks: Delivery Route Optimization

**Input**: Design documents from `specs/001-delivery-route-optimization/`

## Phase 1: Setup (Shared Infrastructure)

- [ ] T001 Add environment keys in `.env.example`: `OPENSTREET_API_URL=https://maps.open-street.com/api/tsp/`, `OPENSTREET_API_KEY`, `QUEUE_CONNECTION=redis`, `BROADCAST_DRIVER=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST`, `REVERB_PORT=8080`
- [ ] T002 Configure Redis as cache and queue driver in `config/queue.php` and `config/cache.php`
- [ ] T003 Configure **Laravel Reverb** as broadcast driver in `config/broadcasting.php`; install `laravel/reverb` package; add Reverb server config (`php artisan reverb:install`); set `BROADCAST_DRIVER=reverb` in `.env`
- [ ] T004 Add rate limiter configuration for route optimization (10 requests/min) in `app/Providers/RouteServiceProvider.php`
- [ ] T005 Add queue worker documentation and a `php artisan queue:work` example to `README.md`
- [ ] T031 Verify Laravel authentication scaffold and `BroadcastAuth` middleware are operational; confirm `private-user.{id}` channel auth resolves correctly before implementing T009/T010

---

## Phase 2: Foundational (Blocking Prerequisites)

- [ ] T006 [P] Create `app/Services/RouteNormalizer.php` — normalize coordinates (round to 5 decimals), stable-sort, and return canonical payload + `sha256` hash
- [ ] T007 [P] Create `app/Services/OpenStreetTspClient.php` — builds GET request to `OPENSTREET_API_URL` with params: `pts=lat,lng|lat,lng|...` (pipe-joined), `nb=N` (auto-set to coordinate count), `mode=driving`, `unit=m`, `tour=closed`, `key=OPENSTREET_API_KEY`; Guzzle timeout=8s; retries=2 with exponential backoff (1s, 2s); maps API response `route[]` → `ordered_stops`, `distance` → `total_distance_m`, `time` → `total_duration_s`; throws typed exceptions for timeout, HTTP error, malformed response
- [ ] T008 [P] Create `app/Services/RouteCache.php` — helper to read/write Redis keys (`route:opt:{user_id}:{hash}`) and pending markers (`route:opt:pending:{job_uuid}`)
- [ ] T009 [P] Create `app/Jobs/OptimizeRouteJob.php` — background job that calls `OpenStreetTspClient`, stores results in Redis (24h TTL), and broadcasts success/failure events; implement `$timeout = 30` and `public function failed(\Throwable $e)` that broadcasts `RouteOptimizationFailed` with `{ job_uuid, error: { code: 'job_failed', message: ... } }` to `private-user.{id}` so frontend is never stuck
- [ ] T010 Create broadcast events `app/Events/RouteOptimized.php` and `app/Events/RouteOptimizationFailed.php` using `ShouldBroadcast` and private channel `private-user.{id}`; success payload: `{ job_uuid, data: { ordered_stops, total_distance_m, total_duration_s } }`; failure payload: `{ job_uuid, error: { code, message } }`
- [ ] T032 Add `OptimizeRouteJob::failed(\Throwable $e)` method that broadcasts `RouteOptimizationFailed` with `code=job_failed`; add `$timeout = 30` property; add integration test in `RouteOptimizationBroadcastTest.php` asserting failure event fires when job throws

---

## Phase 3: User Story 1 - Select addresses and compute the best route (Priority: P1) 🎯 MVP

**Goal**: Accept coordinates, return cached result if available, otherwise queue work and return a `job_uuid`.

**Independent Test**: Submit 3 coordinates; on cache hit receive 200 with route; on cache miss receive 202 with `job_uuid`, and later receive a broadcast with that `job_uuid` and data.

- [ ] T011 [US1] Add API route `POST /api/route/optimize` in `routes/api.php`
- [ ] T012 [US1] Implement `app/Http/Controllers/RouteOptimizationController.php` with validation (min 2, max 10 coords), normalization, Redis cache check, and 200/202 response behavior
- [ ] T013 [US1] Implement `GET /api/route/result/{job_uuid}` in `routes/api.php` and corresponding controller method to allow polling for cached result in `RouteOptimizationController.php`
- [ ] T014 [P] [US1] Add unit tests for `RouteNormalizer` in `tests/Unit/RouteNormalizerTest.php`
- [ ] T015 [US1] Add feature tests in `tests/Feature/RouteOptimizationTest.php` covering cache hit (200) and cache miss (202) dispatch behaviour
- [ ] T016 [US1] Add frontend component `resources/js/routes/OptimizeRouteForm.tsx`:
  - On submit: POST `/api/route/optimize` with `{ coordinates: [[lat, lng], ...] }`
  - **200 response** (cache hit): render result immediately from response body (no WS needed)
  - **202 response** (cache miss): show "pending" spinner; subscribe via `Echo.private('user.' + userId).listen('RouteOptimized', (e) => { if (e.job_uuid === jobUuid) renderResult(e.data); }).listen('RouteOptimizationFailed', (e) => { if (e.job_uuid === jobUuid) showError(e.error); })`; also poll `GET /api/route/result/{job_uuid}` as WS fallback
  - On receive: unsubscribe from channel; render `ordered_stops`, `total_distance_m`, `total_duration_s`

---

## Phase 4: DEFERRED — Address Geocoding (out of scope for this feature)

> Tasks T017–T019, T027–T028 (GeocodingService, RouteSelector, GeocodeController, and their tests) are deferred. This feature accepts coordinates directly; address lookup is a future concern.

---

## Phase 5: User Story 3 - Understand the optimized result and route details (Priority: P3)

**Goal**: Display ordered stops, total estimated distance, and route metadata.

- [ ] T020 [US3] Add frontend `resources/js/routes/RouteResult.tsx` to display ordered stops, summary metrics, and link to map view
- [ ] T021 [US3] Integration smoke test: verify `OptimizeRouteJob` broadcasts `{ job_uuid, data }` on success and `{ job_uuid, error }` on failure to `private-user.{id}` (verification of T009+T010 behaviour, not new implementation)
- [ ] T022 [US3] Refine route summary response shape stored by T009: ensure Redis payload includes ordered stops, total distance, travel estimate, and validation warnings in a consistent schema; depends on T009

---

## Phase N: Polish & Cross-Cutting Concerns

- [ ] T023 [P] Documentation: add `specs/001-delivery-route-optimization/README.md` with env vars, run commands, and example requests
- [ ] T024 [P] Add integration tests for broadcasting and queue processing in `tests/Feature/RouteOptimizationBroadcastTest.php`
- [ ] T025 Add rate-limit tests in `tests/Feature/RateLimitTest.php` to ensure 10 requests/min per user
- [ ] T026 [P] CI: Add a job to run `php artisan test` and a smoke queue worker in CI pipeline (e.g., GitHub Actions); add lint step using PHP CS Fixer/Pint and ESLint
- [ ] T029 [SC-002] Add performance benchmark in `tests/Performance/RouteOptimizationPerfTest.php` asserting end-to-end optimization response (cache miss → broadcast) completes within 10 seconds for 10 locations
- [ ] T030 [P] Configure PHP CS Fixer/Pint (`pint.json`) and ESLint (`.eslintrc`) with project style rules; wire lint check into CI alongside T026

---

## Dependencies & Execution Order

- **Phase 1** (Setup) must complete before Phase 2.
- **Phase 2** (Foundational) must complete before any User Story phase.
- **Phase 3 (US1)** is the MVP and should be implemented first among user stories.
- Within each User Story: tests → services → controllers → frontend → integration tests.

## Parallel Opportunities

- `T006`, `T007`, `T008`, `T009`, `T010` can be implemented in parallel by different engineers.
- Frontend tasks (`T016`, `T020`) can be worked on in parallel with backend foundational tasks once the public API shape is agreed.
- Docs and CI tasks (`T023`, `T026`, `T030`) are parallelizable.
- `T029` (performance test) requires Phase 1–3 complete before meaningful measurement.

## Implementation Strategy

- Deliver MVP: complete Phase 1 → Phase 2 → Phase 3 (US1) with tests and a minimal frontend.
- Iterate: add US2 validation and US3 result display in the next sprint.


---

**Generated tasks file**: `specs/001-delivery-route-optimization/tasks.md`
