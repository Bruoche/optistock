# Tasks: Delivery Route Optimization

**Input**: Design documents from `specs/001-delivery-route-optimization/`

## Implementation Status (2026-06-03)

**Backend complete + verified against the LIVE API** on branch `001-delivery-route-optimization`. 26 feature/unit tests pass (full suite 99 green), Pint clean. Frontend (T016, T020) deferred to a follow-up run per agreed scope.

**Live verification + fixes (2026-06-03)** — `OpenStreetTspClient::optimize()` ran end-to-end against the real API. Two corrections:

- **Response schema was wrong.** Real payload is `{ DIMENSION, TOUR, OPTIMIZATION:[indices], STEPS_DISTANCES:{TOTAL,..}, STEPS_DURATIONS:{TOTAL,..} }` — *not* the guessed `{status, route[], distance, time}`. `OPTIMIZATION` returns input-coordinate indices in visit order (no coords); the client now resolves them back to the caller's coordinates. `mapResponse()`, `plan.md`, and tests updated. See `README.md` §3.
- **Timeouts re-sized for minute-scale calls.** Split connect (15s) vs read (600s); job timeout 660s (config-driven); requires `queue:work --timeout=690` and `DB_QUEUE_RETRY_AFTER=720`. See `README.md` §4.
- **SSL/CA bundle** is a per-machine env requirement (`cURL error 60` otherwise). See `README.md` §1.2.

**Stack reality differs from original plan** — implemented against the actual repo (Laravel 13 / PHP 8.3 / Inertia + React / Fortify auth):

- **Cache + Queue = database driver** (not Redis). Per decision: zero extra infra for an ESGI project; latency/TTL tradeoffs acceptable at this scale.
- **Endpoint = `routes/api.php` registered in `bootstrap/app.php` under `/api` with the `web` middleware group** (session cookie auth, same-origin Inertia) — no Sanctum/API tokens.
- **Broadcast channel = `App.Models.User.{id}`** (Laravel/Reverb convention, authorized in published `routes/channels.php`), not `private-user.{id}`.
- **Rate limiter defined in `AppServiceProvider`** (`route-optimize`, 10/min/user) — Laravel 13 has no `RouteServiceProvider`.
- TSP config read via `config/services.php` (`services.openstreet`), not raw `env()` in code.

## Phase 1: Setup (Shared Infrastructure)

- [X] T001 Add environment keys to `.env` + `.env.example`: `OPENSTREET_API_URL`, `OPENSTREET_API_KEY`, `OPENSTREET_API_TIMEOUT`, `OPENSTREET_API_RETRIES`, `BROADCAST_CONNECTION=reverb`, `REVERB_*`. (Queue/cache left on database driver per decision.)
- [X] T002 Cache + queue use **database driver** (already configured; `cache`/`jobs`/`failed_jobs` migrations present). Deviation from Redis — see status banner.
- [X] T003 Installed `laravel/reverb`; `php artisan reverb:install` published `config/reverb.php`, `config/broadcasting.php`, `routes/channels.php`; `BROADCAST_CONNECTION=reverb`. (Frontend Echo scaffolding deferred to frontend run.)
- [X] T004 Rate limiter `route-optimize` (10/min/user) defined in `app/Providers/AppServiceProvider.php` (no `RouteServiceProvider` in L13); applied via `throttle:route-optimize` on the optimize route.
- [ ] T005 Add queue worker documentation and a `php artisan queue:work` example to `README.md` *(deferred to docs/frontend run)*
- [X] T031 Auth verified: Fortify session auth + published `routes/channels.php` authorizes `App.Models.User.{id}`; api routes run through `web`+`auth` middleware.

---

## Phase 2: Foundational (Blocking Prerequisites)

- [X] T006 [P] `app/Services/RouteNormalizer.php` — round to 5 decimals, stable-sort, canonical payload + `sha256` hash (order-independent cache key).
- [X] T007 [P] `app/Services/OpenStreetTspClient.php` — GET to `services.openstreet.url` with `pts|`, `nb` (auto), `mode/unit/tour`, `key`; split timeout (connect 15s / read 600s); `retries+1` attempts, exponential backoff; maps verified `OPTIMIZATION[]` indices (→ caller coords) + `STEPS_DISTANCES.TOTAL`/`STEPS_DURATIONS.TOTAL` → `ordered_stops/total_distance_m/total_duration_s`; throws typed `RouteOptimizationException`. **Verified live 2026-06-03.**
- [X] T008 [P] `app/Services/RouteCache.php` — read/write result key `route:opt:{userId}:{hash}` (24h) and status key `route:opt:pending:{jobUuid}` (1h, pending/done/failed).
- [X] T009 [P] `app/Jobs/OptimizeRouteJob.php` — calls client, caches result (24h), records status, broadcasts success/failure; `$timeout=30`, `$tries=1`, `failed()` safety net.
- [X] T010 `app/Events/RouteOptimized.php` + `RouteOptimizationFailed.php` — `ShouldBroadcast` on `PrivateChannel('App.Models.User.{id}')`; payloads `{ job_uuid, data }` / `{ job_uuid, error: { code, message } }`.
- [X] T032 `failed(?Throwable)` broadcasts `RouteOptimizationFailed` code `job_failed`; `$timeout=30`; covered by `RouteOptimizationBroadcastTest::test_failed_callback_broadcasts_failure_event`.

---

## Phase 3: User Story 1 - Select addresses and compute the best route (Priority: P1) 🎯 MVP

**Goal**: Accept coordinates, return cached result if available, otherwise queue work and return a `job_uuid`.

**Independent Test**: Submit 3 coordinates; on cache hit receive 200 with route; on cache miss receive 202 with `job_uuid`, and later receive a broadcast with that `job_uuid` and data.

- [X] T011 [US1] `POST /api/route/optimize` in `routes/api.php` (auth + `throttle:route-optimize`).
- [X] T012 [US1] `app/Http/Controllers/RouteOptimizationController.php@store` + `app/Http/Requests/OptimizeRouteRequest.php` — validate (2–10 coords, lat/lng range), normalize, cache check, 200 (hit) / 202 (miss).
- [X] T013 [US1] `GET /api/route/result/{job_uuid}` + `@result` — returns cached status (pending/done/failed) or 404.
- [X] T014 [P] [US1] `tests/Unit/RouteNormalizerTest.php` (rounding, hash, order-independence).
- [X] T015 [US1] `tests/Feature/RouteOptimizationTest.php` — 401 unauth, 422 validation, 202 cache miss (job queued), 200 cache hit, result-endpoint status/404.
- [X] T033 [US1] `tests/Unit/OpenStreetTspClientTest.php` (C1) — success mapping, query params, api_error/invalid_response/timeout paths.
- [X] T034 [US1] `tests/Unit/RouteCacheTest.php` (C2) — key namespacing, result round-trip, per-user isolation, status transitions.
- [ ] T016 [US1] *(deferred to frontend run)* Add frontend component in `resources/js/pages/` (NOT `resources/js/routes/` — that dir is Wayfinder-generated). Use channel `App.Models.User.{id}`:
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

- [ ] T020 [US3] *(deferred to frontend run)* Add frontend page in `resources/js/pages/` to display ordered stops, summary metrics, and link to map view
- [X] T021 [US3] Broadcast verification covered by `RouteOptimizationBroadcastTest` (success → `RouteOptimized {job_uuid,data}`, failure → `RouteOptimizationFailed {job_uuid,error}`).
- [X] T022 [US3] Response schema fixed and consistent across client/job/events/cache: `{ ordered_stops, total_distance_m, total_duration_s }`. (Validation handled pre-dispatch in controller; no warnings in result payload.)

---

## Phase N: Polish & Cross-Cutting Concerns

- [X] T023 [P] Documentation: `specs/001-delivery-route-optimization/README.md` — env vars, CA-bundle setup, run commands, verified API contract, timeout layering, HTTP/WS API, verification steps.
- [X] T024 [P] `tests/Feature/RouteOptimizationBroadcastTest.php` — success broadcast + cache, api_error/invalid_response failure broadcasts, `failed()` callback broadcast.
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
