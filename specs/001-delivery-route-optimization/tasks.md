# Tasks: Delivery Route Optimization

**Input**: Design documents from `specs/001-delivery-route-optimization/`

## Phase 1: Setup (Shared Infrastructure)

- [ ] T001 Add environment keys for routing, queue, and broadcasting in `.env.example` (OPENSTREET_API_URL, OPENSTREET_API_KEY, QUEUE_CONNECTION=redis, BROADCAST_DRIVER)
- [ ] T002 Configure Redis as cache and queue driver in `config/queue.php` and `config/cache.php`
- [ ] T003 Configure broadcasting driver and WebSocket defaults in `config/broadcasting.php`
- [ ] T004 Add rate limiter configuration for route optimization (10 requests/min) in `app/Providers/RouteServiceProvider.php`
- [ ] T005 Add queue worker documentation and a `php artisan queue:work` example to `README.md`

---

## Phase 2: Foundational (Blocking Prerequisites)

- [ ] T006 [P] Create `app/Services/RouteNormalizer.php` — normalize coordinates (round to 5 decimals), stable-sort, and return canonical payload + `sha256` hash
- [ ] T007 [P] Create `app/Services/OpenStreetTspClient.php` — client with configurable `OPENSTREET_API_URL`/`OPENSTREET_API_KEY`, timeouts, and retry strategy
- [ ] T008 [P] Create `app/Services/RouteCache.php` — helper to read/write Redis keys (`route:opt:{user_id}:{hash}`) and pending markers (`route:opt:pending:{job_uuid}`)
- [ ] T009 [P] Create `app/Jobs/OptimizeRouteJob.php` — background job that calls `OpenStreetTspClient`, stores results in Redis (24h TTL), and broadcasts success/failure events
- [ ] T010 Create broadcast events `app/Events/RouteOptimized.php` and `app/Events/RouteOptimizationFailed.php` using `ShouldBroadcast` and private channel `private-user.{id}`

---

## Phase 3: User Story 1 - Select addresses and compute the best route (Priority: P1) 🎯 MVP

**Goal**: Accept coordinates, return cached result if available, otherwise queue work and return a `job_uuid`.

**Independent Test**: Submit 3 coordinates; on cache hit receive 200 with route; on cache miss receive 202 with `job_uuid`, and later receive a broadcast with that `job_uuid` and data.

- [ ] T011 [US1] Add API route `POST /api/route/optimize` in `routes/api.php`
- [ ] T012 [US1] Implement `app/Http/Controllers/RouteOptimizationController.php` with validation (min 2, max 10 coords), normalization, Redis cache check, and 200/202 response behavior
- [ ] T013 [US1] Implement `GET /api/route/result/{job_uuid}` in `routes/api.php` and corresponding controller method to allow polling for cached result in `RouteOptimizationController.php`
- [ ] T014 [P] [US1] Add unit tests for `RouteNormalizer` in `tests/Unit/RouteNormalizerTest.php`
- [ ] T015 [US1] Add feature tests in `tests/Feature/RouteOptimizationTest.php` covering cache hit (200) and cache miss (202) dispatch behaviour
- [ ] T016 [US1] Add frontend component `resources/js/routes/OptimizeRouteForm.tsx` to submit coordinates, show 202 state, and wait for broadcast or poll endpoint

---

## Phase 4: User Story 2 - Review and adjust addresses before optimization (Priority: P2)

**Goal**: Allow adding/removing addresses and validate them before optimization.

- [ ] T017 [US2] Implement `app/Services/GeocodingService.php` for address validation / geocoding using OpenStreet (used by frontend validation and controller)
- [ ] T027 [US2] Add unit tests for `GeocodingService` in `tests/Unit/GeocodingServiceTest.php` covering valid address resolution, invalid address handling, and API error paths
- [ ] T018 [US2] [FR-007] Add frontend `resources/js/routes/RouteSelector.tsx` for selecting, deduplicating, and confirming addresses before optimization; must persist selection in local component state until user clears or re-submits
- [ ] T019 [US2] Add API validation endpoint `POST /api/route/validate` in `routes/api.php` and `app/Http/Controllers/GeocodeController.php` (optional synchronous validation)
- [ ] T028 [US2] Add feature tests for `POST /api/route/validate` in `tests/Feature/GeocodeValidationTest.php` covering valid geocode response, unresolvable address (422), and external API failure (503)

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
- Frontend tasks (`T016`, `T018`, `T020`) can be worked on in parallel with backend foundational tasks once the public API shape is agreed.
- Docs and CI tasks (`T023`, `T026`, `T030`) are parallelizable.
- Test tasks `T027`, `T028` can be developed alongside T017/T019 respectively (TDD approach).
- `T029` (performance test) requires Phase 1–3 complete before meaningful measurement.

## Implementation Strategy

- Deliver MVP: complete Phase 1 → Phase 2 → Phase 3 (US1) with tests and a minimal frontend.
- Iterate: add US2 validation and US3 result display in the next sprint.


---

**Generated tasks file**: `specs/001-delivery-route-optimization/tasks.md`
