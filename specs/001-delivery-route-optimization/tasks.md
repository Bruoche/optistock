# Tasks: Delivery Route Optimization

**Input**: Design documents from `specs/001-delivery-route-optimization/`

## Implementation Status (2026-06-03)

**Backend complete + verified against the LIVE API** on branch `001-delivery-route-optimization`. 26 feature/unit tests pass (full suite 99 green), Pint clean. Frontend (T016, T020) deferred to a follow-up run per agreed scope.

**Live verification + fixes (2026-06-03)** — `OpenStreetTspClient::optimize()` ran end-to-end against the real API. Two corrections:

- **Response schema was wrong.** Real payload is `{ DIMENSION, TOUR, OPTIMIZATION:[indices], STEPS_DISTANCES:{TOTAL,..}, STEPS_DURATIONS:{TOTAL,..} }` — *not* the guessed `{status, route[], distance, time}`. `OPTIMIZATION` returns input-coordinate indices in visit order (no coords); the client now resolves them back to the caller's coordinates. `mapToTour()`, `plan.md`, and tests updated. See `README.md` §3.
- **Timeouts re-sized for minute-scale calls.** Split connect (15s) vs read (600s); job timeout 1260s (config-driven); requires `queue:work --timeout=1290` and `DB_QUEUE_RETRY_AFTER=1320`. See `README.md` §4.
- **SSL/CA bundle** is a per-machine env requirement (`cURL error 60` otherwise). See `README.md` §1.2.

**Stack reality differs from original plan** — implemented against the actual repo (Laravel 13 / PHP 8.3 / Inertia + React / Fortify auth):

- **Cache + Queue = database driver** (not Redis). Per decision: zero extra infra for an ESGI project; latency/TTL tradeoffs acceptable at this scale.
- **Endpoint = `routes/api.php` registered in `bootstrap/app.php` under `/api` with the `web` middleware group** (session cookie auth, same-origin Inertia) — no Sanctum/API tokens.
- **Broadcast channel = `App.Models.User.{id}`** (Laravel/Reverb convention, authorized in published `routes/channels.php`), not `private-user.{id}`.
- **Rate limiter defined in `AppServiceProvider`** (`tour-optimize`, 10/min/user) — Laravel 13 has no `RouteServiceProvider`.
- TSP config read via `config/services.php` (`services.openstreet`), not raw `env()` in code.

## Phase 1: Setup (Shared Infrastructure)

- [X] T001 Add environment keys to `.env` + `.env.example`: `OPENSTREET_API_URL`, `OPENSTREET_API_KEY`, `OPENSTREET_API_TIMEOUT`, `OPENSTREET_API_RETRIES`, `BROADCAST_CONNECTION=reverb`, `REVERB_*`. (Queue/cache left on database driver per decision.)
- [X] T002 Cache + queue use **database driver** (already configured; `cache`/`jobs`/`failed_jobs` migrations present). Deviation from Redis — see status banner.
- [X] T003 Installed `laravel/reverb`; `php artisan reverb:install` published `config/reverb.php`, `config/broadcasting.php`, `routes/channels.php`; `BROADCAST_CONNECTION=reverb`. (Frontend Echo scaffolding deferred to frontend run.)
- [X] T004 Rate limiter `tour-optimize` (10/min/user) defined in `app/Providers/AppServiceProvider.php` (no `RouteServiceProvider` in L13); applied via `throttle:tour-optimize` on the optimize route.
- [ ] T005 Add queue worker documentation and a `php artisan queue:work` example to `README.md` *(deferred to docs/frontend run)*
- [X] T031 Auth verified: Fortify session auth + published `routes/channels.php` authorizes `App.Models.User.{id}`; api routes run through `web`+`auth` middleware.

---

## Phase 2: Foundational (Blocking Prerequisites)

- [X] T006 [P] `app/Services/CoordinateNormalizer.php` — round to 5 decimals, stable-sort into a canonical, order-independent coordinate list (the service sha256-hashes it into the cache key).
- [X] T007 [P] `app/Services/OpenStreetTspClient.php` — GET to `services.openstreet.url` with `pts|`, `nb` (auto), `mode/unit/tour`, `key`; split timeout (connect 15s / read 600s); `retries+1` attempts, exponential backoff; maps verified `OPTIMIZATION[]` indices (→ caller coords) + `STEPS_DISTANCES.TOTAL`/`STEPS_DURATIONS.TOTAL` → `ordered_stops/total_distance_m/total_duration_s`; throws typed `TourOptimizationException`. **Verified live 2026-06-03.**
- [X] T008 [P] `app/Services/TourCache.php` — read/write result key `tour:{hash}` (24h) and status key `tour:status:{jobUuid}` (1h, pending/done/failed).
- [X] T009 [P] `app/Jobs/OptimizeTourJob.php` — calls client, caches result (24h), records status, broadcasts success/failure; `$timeout` from config (1260), `$tries=1`, `failed()` safety net.
- [X] T010 `app/Events/TourOptimized.php` + `TourOptimizationFailed.php` — `ShouldBroadcast` on `PrivateChannel('App.Models.User.{id}')`; payloads `{ job_uuid, data }` / `{ job_uuid, error: { code, message } }`.
- [X] T032 `failed(?Throwable)` broadcasts `TourOptimizationFailed` code `job_failed`; `$timeout` from config (1260); covered by `TourOptimizationBroadcastTest::test_failed_callback_broadcasts_failure_event`.

---

## Phase 3: User Story 1 - Select addresses and compute the best route (Priority: P1) 🎯 MVP

**Goal**: Accept coordinates, return cached result if available, otherwise queue work and return a `job_uuid`.

**Independent Test**: Submit 3 coordinates; on cache hit receive 200 with route; on cache miss receive 202 with `job_uuid`, and later receive a broadcast with that `job_uuid` and data.

- [X] T011 [US1] `POST /api/tour/optimize` in `routes/api.php` (auth + `throttle:tour-optimize`).
- [X] T012 [US1] `TourOptimizationController@optimizeTour` (thin) delegates to `app/Services/TourOptimizationService.php`; `OptimizeTourRequest` validates (2–10 coords, lat/lng range); service normalizes, hashes, cache-checks, dedups, dispatches → 200 (hit) / 202 (miss).
- [X] T013 [US1] `GET /api/tour/status/{job_uuid}` + `@getJobStatus` — returns cached status (pending/done/failed) or 404.
- [X] T014 [P] [US1] `tests/Unit/CoordinateNormalizerTest.php` (rounding, hash, order-independence).
- [X] T015 [US1] `tests/Feature/TourOptimizationTest.php` — 401 unauth, 422 validation, 202 cache miss (job queued), 200 cache hit, result-endpoint status/404.
- [X] T033 [US1] `tests/Unit/OpenStreetTspClientTest.php` (C1) — success mapping, query params, api_error/invalid_response/timeout paths.
- [X] T034 [US1] `tests/Unit/TourCacheTest.php` (C2) — key namespacing, result round-trip, per-user isolation, status transitions.
- [~] T016 [US1] *(superseded — expanded into fine-grained tasks T040–T045 in Phase 6: Front-End)*

---

## Phase 4: DEFERRED — Address Geocoding (out of scope for this feature)

> Tasks T017–T019, T027–T028 (GeocodingService, RouteSelector, GeocodeController, and their tests) are deferred. This feature accepts coordinates directly; address lookup is a future concern.

---

## Phase 5: User Story 3 - Understand the optimized result and route details (Priority: P3)

**Goal**: Display ordered stops, total estimated distance, and route metadata.

- [~] T020 [US3] *(superseded — expanded into fine-grained tasks T047–T048 in Phase 6: Front-End)*
- [X] T021 [US3] Broadcast verification covered by `TourOptimizationBroadcastTest` (success → `TourOptimized {job_uuid,data}`, failure → `TourOptimizationFailed {job_uuid,error}`).
- [X] T022 [US3] Response schema fixed and consistent across client/job/events/cache: `{ ordered_stops, total_distance_m, total_duration_s }`. (Validation handled pre-dispatch in controller; no warnings in result payload.)

---

## Phase 6: Front-End (added 2026-06-07)

**Stack**: reuse Laravel React Starter Kit + shadcn/ui on Tailwind v4. New deps only: `maplibre-gl`, `react-map-gl`, `laravel-echo`, `pusher-js`. See `plan.md` "Front-End Implementation Plan", `research.md`, `data-model.md`, `contracts/frontend-ui.md`, `quickstart.md`.

**Independent Test**: Log in → click ≥2 map points (stops appear in list, Optimize enables) → press Optimize (list greys, bottom "Optimizing…" bar) → on `.TourOptimized` (or 200 cache hit) route line drawn + total duration shown where button was; forced failure → `sonner` toast + list re-enabled (never stuck).

### Front-end Setup & Foundational

- [ ] T035 [P] Install front-end deps `maplibre-gl`, `react-map-gl`, `laravel-echo`, `pusher-js` (update `package.json` + `package-lock.json` via `npm install`).
- [ ] T036 Re-theme role CSS vars in `resources/css/app.css` — `:root` (background `#FFFFFF`, foreground `#000000`, primary `#FF9A3C`, secondary `#FFCF8C`, accent `#FFC802`, all `-foreground` `#000000`) and `.dark` (background `#11100F`, foreground `#FFFFFF`, primary `#F99435`, secondary `#FFCF8C`, accent `#FFC802`); add `--text-on-color` (`#000000` light / `#11100F` dark) and register `--color-text-on-color: var(--text-on-color);` in the `@theme` block. No parallel palette, no off-palette literals (FR-017/FR-018, Constitution VI).
- [ ] T037 [P] Add `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` to `.env` and `.env.example`.
- [ ] T038 Create Echo singleton in `resources/js/lib/echo.ts` (`broadcaster: 'reverb'`, `Pusher` from `pusher-js`, config from `import.meta.env.VITE_REVERB_*`). Depends on T035, T037.
- [ ] T039 [P] Define front-end types in `resources/js/types/tour.ts` (`Stop`, `OptimizedStop`, `TourResult`, `TourError`, `OptimizeState`) per `data-model.md`.

### Phase 6 — User Story 1 (P1): pick coordinates, submit, loading state

- [ ] T040 [P] [US1] `resources/js/components/tour/route-layer.tsx` — FR-019 isolation boundary. Props `{ path: {lat,lng}[] }`; render straight-line segments as a GeoJSON `LineString` (`<Source>`+`<Layer>`). No page/list logic inside; only consumes path. Depends on T035, T039.
- [ ] T041 [US1] `resources/js/components/tour/tour-map.tsx` — wrap `react-map-gl` Map (OSM-compatible style); click adds a `Stop`; render numbered `<Marker>` per stop; emit add/select callbacks. Depends on T035, T039.
- [ ] T042 [P] [US1] `resources/js/components/tour/optimizing-bar.tsx` — bottom horizontal bar, reuse `components/ui/spinner.tsx` + "Optimizing…" text (FR-013). Depends on T039.
- [ ] T046 [US1] `resources/js/components/tour/stop-list.tsx` — **display** the placed stops as a list beneath the map; Optimize `<Button>` slot on top, disabled when `<2` stops; greyed + non-interactive (`opacity-50 pointer-events-none`, `aria-disabled`) while `pending` (FR-010 display, FR-011, FR-012). Per-row remove is added later in US2 (T051); ship a non-removable list first so US1 is independently shippable. Depends on T039.
- [ ] T043 [US1] `resources/js/hooks/use-tour-optimization.ts` — `OptimizeState` machine; POST `/api/tour/optimize` `{coordinates:[[lat,lng]...]}`; 200⇒`done` from body, 202⇒`pending` subscribe `Echo.private('App.Models.User.'+userId)` filter by `job_uuid` on `.TourOptimized`/`.TourOptimizationFailed`; poll `GET /api/tour/status/{job_uuid}` as WS fallback; unsubscribe on terminal; failure ⇒ `sonner` toast (FR-004/006/008, contract). Depends on T038, T039.
- [ ] T044 [US1] Inertia GET route + thin controller render for the page (`routes/web.php` → `Inertia::render('tour/optimize')`, behind `auth`); pass `userId` prop. Depends on none (backend-side render only).
- [ ] T045 [US1] `resources/js/pages/tour/optimize.tsx` — screen layout: map top ~2/3 (`TourMap` + `RouteLayer`), lower third hosts `StopList` with Optimize `<Button>` on top; bottom-anchored `OptimizingBar` while `pending`; wire `use-tour-optimization` (FR-009). Depends on T040, T041, T042, T043, T044, T046.

### Phase 6 — User Story 2 (P2): review & remove stops

- [ ] T051 [US2] Add per-row **remove** to `resources/js/components/tour/stop-list.tsx` (`lucide-react` trash icon per row); removing a stop also removes its map marker via shared state and excludes it from the next request (FR-002, FR-010 remove). Builds on the US1 list (T046). Depends on T046.

### Phase 6 — User Story 3 (P3): result & duration display

- [ ] T047 [P] [US3] `resources/js/components/tour/result-summary.tsx` — replaces the Optimize button row on `done`; show total tour duration formatted from `total_duration_s` at top; leave freed list space empty (reserved future drivers list) (FR-014/FR-015). Depends on T039.
- [ ] T048 [US3] Wire result into page: on `done`, draw optimized `ordered_stops` via `RouteLayer` and swap button row → `ResultSummary` without reload (FR-014, SC-007). Depends on T045, T047.

### Phase 6 — Polish (front-end)

- [ ] T049 [P] Cohesion audit: grep `resources/js/components/tour/` + `pages/tour/` for raw hex in className/style — expect none; only role utilities (`bg-primary`, `text-foreground`, `text-text-on-color`). (Constitution VI, quickstart §6.)
- [ ] T050 Manual smoke test per `quickstart.md` §5 — US1 happy path (cache miss → WS), 200 cache-hit path, and forced-failure path (worker down / bad key) → toast + list re-enabled.

---

## Phase N: Polish & Cross-Cutting Concerns

- [X] T023 [P] Documentation: `specs/001-delivery-route-optimization/README.md` — env vars, CA-bundle setup, run commands, verified API contract, timeout layering, HTTP/WS API, verification steps.
- [X] T024 [P] `tests/Feature/TourOptimizationBroadcastTest.php` — success broadcast + cache, api_error/invalid_response failure broadcasts, `failed()` callback broadcast.
- [ ] T025 Add rate-limit tests in `tests/Feature/RateLimitTest.php` to ensure 10 requests/min per user
- [ ] T026 [P] CI: Add a job to run `php artisan test` and a smoke queue worker in CI pipeline (e.g., GitHub Actions); add lint step using PHP CS Fixer/Pint and ESLint
- [ ] T029 [SC-002] Add performance benchmark in `tests/Performance/TourOptimizationPerfTest.php` asserting end-to-end optimization response (cache miss → broadcast) completes within 10 seconds for 10 locations
- [ ] T030 [P] Configure PHP CS Fixer/Pint (`pint.json`) and ESLint (`.eslintrc`) with project style rules; wire lint check into CI alongside T026

---

## Dependencies & Execution Order

- **Phase 1** (Setup) must complete before Phase 2.
- **Phase 2** (Foundational) must complete before any User Story phase.
- **Phase 3 (US1)** is the MVP and should be implemented first among user stories.
- Within each User Story: tests → services → controllers → frontend → integration tests.
- **Phase 6 (Front-End)** ordering: T035–T039 (deps/theme/env/echo/types) → components (T040–T042, T046, T047) → hook T043 + render route T044 → page T045 → result wiring T048 → US2 remove T051 → polish T049–T050.
- **US1 is independently shippable**: it includes the stop-list *display* (T046). US2 (T051) only *adds* per-row remove on top — US1 does not depend on any US2 task.

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
