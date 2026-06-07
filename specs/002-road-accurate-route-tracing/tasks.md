# Tasks: Road-Accurate Route Tracing

**Input**: Design documents from `specs/002-road-accurate-route-tracing/`
**Builds on**: feature 001 (complete) — reuses the optimized result, the `RouteLayer` coordinate
boundary (001 FR-019), and `ResultSummary`.

**Stack**: Laravel 13 / PHP 8.3 backend; React 19 + Inertia + Tailwind v4 + shadcn/ui front-end.
`/route` is **synchronous** — no queue/job/WebSocket. See `plan.md`, `research.md`, `data-model.md`,
`contracts/tour-geometry.md`, `quickstart.md`.

## Phase 1: Setup

- [ ] T001 [P] Add `OPENSTREET_ROUTE_URL` (default `https://maps.open-street.com/api/route/`), `OPENSTREET_ROUTE_TIMEOUT` (default `15`), and `OPENSTREET_MODE` (default `trucking`) to `.env` and `.env.example`. Reuse existing `OPENSTREET_API_KEY`.
- [ ] T002 Add `route_url`, `route_timeout`, and `mode` (default `trucking`) under `services.openstreet` in `config/services.php` (read from the T001 env keys). `mode` is the single source for BOTH the TSP call (001) and the geometry call (002).

---

## Phase 2: Foundational (blocking prerequisites)

- [ ] T003 [P] `app/Services/PolylineDecoder.php` — decode a Google encoded polyline (precision 5) into `array<int, array{0: float, 1: float}>` (`[[lat,lng],…]`). Pure function, no deps. (research.md R1.)
- [ ] T004 [P] `tests/Unit/PolylineDecoderTest.php` — decode Google's canonical vector `` _p~iF~ps|U_ulLnnqC_mqNvxq`@ `` → `[[38.5,-120.2],[40.7,-120.95],[43.252,-126.453]]`; empty string → `[]`.
- [ ] T005 `app/Services/OpenStreetRouteClient.php` — GET `services.openstreet.route_url` with `origin`, `destination`, `mode`, `key`; short timeout (`route_timeout`); map `{polyline,total_distance,total_time,status}` → `{coordinates[], distance_m, duration_s}` via `PolylineDecoder`; success iff `status` is `0`/`"OK"`, else throw typed failure carrying the status code (`SYNTAX_ERROR`/`LIMIT_REACHED`/`WRONG_KEY`/`REQUEST_DENIED`); HTTP-fail/timeout → typed failure. Depends on T002, T003.
- [ ] T006 [P] `tests/Unit/OpenStreetRouteClientTest.php` — success mapping (polyline decoded, metres/seconds), query params (origin/destination/mode/key), `status` failure codes, HTTP non-2xx, timeout.
- [ ] T007 `app/Services/TourGeometryService.php` — `trace(orderedStops, mode)`: build consecutive legs incl. the closing leg (last→first); call `OpenStreetRouteClient` per leg; per-leg `try/catch` → on failure `Log::warning` (leg index + coords + status) and mark `ok:false` (FR-006/FR-009); compound `total_distance_m`/`total_duration_s`; totals are `null` if **any** leg failed (FR-008). Depends on T005.
- [ ] T008 In `app/Providers/AppServiceProvider.php`: (a) register `OpenStreetRouteClient` (inject `route_url`, `key`, `route_timeout`, `mode` from config) mirroring the `OpenStreetTspClient` binding; (b) define a **dedicated** `tour-geometry` rate limiter (e.g. 30/min/user), separate from `tour-optimize`.
- [ ] T024 Centralise the mode (default `trucking`): update `app/Services/OpenStreetTspClient.php` to take a `mode` constructor arg and use it instead of the hard-coded `driving`; pass `services.openstreet.mode` in the TSP binding (T008 file); update `tests/Unit/OpenStreetTspClientTest.php` query-param assertion `mode === 'driving'` → `'trucking'`. (Keeps 001's optimization mode congruent with 002's geometry mode.)

---

## Phase 3: User Story 1 - See the real road path (Priority: P1) 🎯 MVP

**Goal**: After a result is shown with straight lines, replace them with the road-following path.

**Independent Test**: Optimize ≥3 stops → straight lines first, then a road path covering every leg
(incl. return) in visit order.

- [ ] T009 [US1] `app/Http/Requests/TourGeometryRequest.php` — validate `stops` (2–10, `[lat,lng]`, ranges) and `mode` (`driving|walking|trucking`, default `trucking`). No mode selector exists yet (M1) — `mode` defaults to the config value.
- [ ] T010 [US1] `app/Http/Controllers/TourGeometryController.php` (thin) — delegate to `TourGeometryService::trace`, return the aggregated payload (per `contracts/tour-geometry.md`). Depends on T007, T009.
- [ ] T011 [US1] `POST /api/tour/geometry` in `routes/api.php` (auth + `throttle:tour-geometry` — the dedicated limiter from T008, NOT `tour-optimize`). Depends on T010, T008.
- [ ] T012 [P] [US1] `tests/Feature/TourGeometryTest.php` — 200 with decoded legs + compounded totals (fake `Http`), 422 invalid stops/mode, 401 unauth.
- [ ] T013 [P] [US1] Add `LegGeometry` + `TourGeometry` types to `resources/js/types/tour.ts` (per data-model.md).
- [ ] T014 [US1] `resources/js/hooks/use-tour-geometry.ts` — **new, separate hook** (do NOT modify 001's `use-tour-optimization.ts`). Given the done result's ordered stops, POST them (+ `mode`, default `trucking`) to `/api/tour/geometry`; store `geometry`; expose a composed `RoutePath` (road coords where `legs[i].ok`, straight segment otherwise — FR-006). Fetch runs only AFTER the result is shown, never blocking it (FR-007). Track the current result identity (a token bumped on each new optimization / reset) and ignore any response that arrives for a superseded result (FR-010, M3) — do **not** rely on a `job_uuid` (a 200 cache-hit result carries none). Depends on T013.
- [ ] T015 [US1] `resources/js/pages/tour/optimize.tsx` — compose `use-tour-geometry` alongside the existing `use-tour-optimization` (the page wires the two; neither hook depends on the other). Feed the composed road path to `RouteLayer` when geometry is present (interface unchanged, 001 FR-019); straight lines remain until it arrives (FR-002). Depends on T014.
- [ ] T016 [P] [US1] `resources/js/hooks/use-tour-geometry.test.ts` — geometry fetch success → composed path uses road coords; ordering preserved.

---

## Phase 4: User Story 2 - Accurate estimate incl. 2-point (Priority: P2)

**Goal**: Replace the initial estimate with the road-accurate one; fill 2-point "Unavailable".

**Independent Test**: ≥3-stop tour estimate updates to road value; 2-point starts "Unavailable" then resolves.

- [ ] T017 [US2] `resources/js/components/tour/result-summary.tsx` + hook wiring — show the initial estimate first, then replace duration/distance with `TourGeometry.total_*` when non-null; resolve the 2-point `null` → road value (FR-003/FR-004). If totals are null (a leg failed), keep the initial estimate (FR-008). Depends on T014.
- [ ] T018 [US2] `resources/js/hooks/use-tour-geometry.test.ts` (extend) — road totals replace the initial estimate; 2-point `null` initial → resolved from geometry; null totals keep the initial.

---

## Phase 5: User Story 3 - Graceful fallback (Priority: P3)

**Goal**: Failures never break the result; straight lines + initial estimate persist; failures logged.

**Independent Test**: Force `/route` failure → straight lines + initial estimate remain, warning logged,
no blank state; per-leg failure → only that leg falls back.

- [ ] T019 [US3] `tests/Feature/TourGeometryTest.php` (extend) — per-leg failure (one upstream leg errors) → that leg `ok:false`, totals `null`; whole-tour failure path; assert a warning is logged.
- [ ] T020 [US3] `resources/js/hooks/use-tour-geometry.ts` — if `/api/tour/geometry` returns non-200 or the request errors, keep the straight-line path + initial estimate (FR-005); ignore a geometry response for a superseded result or after reset, using the result-identity token from T014 (FR-010, stale guard).
- [ ] T021 [US3] `resources/js/hooks/use-tour-geometry.test.ts` (extend) — fetch failure keeps straight + initial; a late response for a reset/superseded result is ignored.

---

## Phase 6: Polish & Cross-Cutting

- [ ] T022 [P] Cohesion audit: no raw hex in `resources/js/components/tour/` + `pages/tour/` JSX (route color reads `--primary` var; Constitution VI).
- [ ] T023 Manual smoke per `quickstart.md` §4–5: confirm polyline **precision 5** renders on real roads (else switch decoder to 6) and `total_time` magnitude is seconds (else ms); verify ≥3-stop, 2-point, and forced-failure paths.

---

## Dependencies & Execution Order

- **Phase 1 (Setup)** → **Phase 2 (Foundational)** → user-story phases.
- Foundational order: T003 (decoder) + T004 → T005 (client, needs decoder) + T006 → T007 (service, needs client) ; T008 (binding + limiter) after T005 ; T024 (centralise mode → updates 001's TSP client + test) needs T002.
- **US1 (Phase 3)** is the MVP and the first deliverable; US2 and US3 build on it.
- US2 (T017) needs the geometry fetch (T014). US3 (T020) extends the same **new** `use-tour-geometry` hook (001's `use-tour-optimization` is NOT modified).
- **T024 touches committed 001 code** (`OpenStreetTspClient` + its test) — keep it a small, isolated change; run the full backend suite after it (the TSP query-param test asserts the mode).

## Parallel Opportunities

- `T001`, `T003`, `T004`, `T006` (distinct files) can run in parallel.
- `T012`, `T013`, `T016` are `[P]` once their deps are met; front type task `T013` can start as soon as data-model is fixed.
- Test tasks `T004`, `T006`, `T012` parallelize with sibling code once their target exists.
- `T016`, `T018`, `T021` all extend the **same** `use-tour-geometry.test.ts` — run them **sequentially** (only T016 is `[P]` vs other files; T018/T021 append to it).

## Implementation Strategy

- **MVP**: Phase 1 → Phase 2 → Phase 3 (US1) = road path replaces straight lines.
- Then US2 (accurate metrics, 2-point fill) and US3 (robust fallback + logging).
- **First-render gate** (T023): confirm the two soft assumptions (polyline precision, time unit) before
  considering the feature done — cheap, catches the only residual unknowns.

---

**Generated tasks file**: `specs/002-road-accurate-route-tracing/tasks.md`
