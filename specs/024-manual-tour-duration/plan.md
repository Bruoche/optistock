# Implementation Plan: Manual Tour Duration Fallback

**Branch**: `024-manual-tour-duration` | **Date**: 2026-07-08 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/024-manual-tour-duration/spec.md`

## Summary

When the external routing API is unavailable, automatic optimization fails and today nothing is saved — a hard dead-end. This feature adds a **manual fallback**: on an optimization failure the top bar reveals a **tour drive-duration field** (minutes) and a **Force Tour** button. Force Tour calls a **new synchronous endpoint** `POST /api/tour/force` that writes a tour straight to the `tours` table — stops kept in the dispatcher's **current (entered) order**, no reorder, no upstream call — using the typed value as the tour's `travel_duration_s` (the driving total the dead API would have produced). Per-stop delivery durations are saved unchanged, exactly as today. The response mirrors the optimize `done` payload, so the existing result view + driver-assignment flow work on the forced tour with no further change.

Second thrust (user-mandated): **audit the entire driver-assignment back-end** so no external-API outage can block it. The path is already best-effort by design (unknown legs count 0 and flag `projected_incomplete`, unroutable connections log + degrade, geometry legs fall back to straight lines). The audit confirms every external call in the driver path is bounded and non-throwing, and fixes the one real gap: the `/route` client (`OpenStreetRouteClient`) sets a read timeout but **no connect timeout**, so a dead host stalls each pooled batch for the full read timeout — a fail-fast connect timeout is added (behavior-additive robustness), mirroring the TSP client.

Transparency (constitution IV, spec US2): a forced tour is visibly marked **manually entered** in the result view; distance/road metrics stay shown as unknown (never zero); every handled failure keeps logging with context.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12 (backend) + TypeScript / React 19 + Inertia (frontend)

**Primary Dependencies**: Laravel (Eloquent, validation, HTTP client + Pool), existing OpenStreet clients; React, `sonner` toasts, existing `ActionButton` / control-bar components; Tailwind v4 palette

**Storage**: MySQL — **no schema/migration change**. A forced tour reuses the existing `tours` columns (`travel_duration_s` = manual drive seconds, `total_distance_m` = null) and `stops` rows (input order via `position`)

**Testing**: Pest/PHPUnit (`php artisan test`); Vitest + Testing Library (`npm test`); full gate = lint + types + prettier `format:check` + both suites

**Target Platform**: Containerized web app (feature 008)

**Project Type**: Web application — Laravel backend + Inertia/React frontend (both touched)

**Performance Goals**: Force endpoint is a single synchronous DB write (no upstream call) — sub-100ms typical. Driver-availability audit must not regress the existing routing/call count; the connect-timeout change only makes a dead host fail *faster*.

**Constraints** (binding, from spec + user):
- The manual field + Force Tour appear **only after an optimization request errors** — never as a default control (spec FR-001/FR-003).
- Manual value fills **only** the tour drive duration (`travel_duration_s`); per-stop durations saved unchanged (spec FR-005).
- Stops saved in **input order**, no reorder (FR-004); no upstream call on the force path (synchronous).
- Reject missing/zero/negative/over-max duration with a clear message, no save (FR-006); same stop-count + coordinate rules as optimize (FR-007); same owned+unassigned `tour_id` edit-in-place semantics + vanished-target → `persist_failed` (FR-008).
- Forced tour is assignable exactly like an optimized one (FR-009); driver workday uses its saved duration (FR-010).
- **No blocking** anywhere in the driver-assignment back-end when the API is down; every degraded value is flagged/approximate, never a silent zero (FR-011–FR-013); every handled failure logged (FR-015, constitution IV).
- Existing endpoint I/O + optimize/assignment behavior unchanged — this feature is **additive**.
- Front-end styling: role-named palette variables + reusable classes/components only; no raw color literals (constitution VI).

**Scale/Scope**: One new endpoint (route + thin controller + form request + service), reuse of `TourRecorder`/`TourRepository` unchanged; one client hardening (connect timeout) + config key; frontend — control-bar gains the conditional field+button, the optimization hook gains `forceTour`, the done state carries a `forced` flag, `ResultSummary` shows a manual-duration badge. No migration, no change to optimize/geometry/assign contracts.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Quality First**: Additive path proven by new contract + feature tests; existing suite stays green. Driver-path audit is verified by tests exercising an API-down scenario. PASS.
- **II. Readable by Default**: New pieces are small and intent-named (`TourForceController::force`, `TourForcingService::force`, `forceTour` hook action). Comments only for the non-obvious (why order = input order; why distance is null). PASS.
- **III. Simple & Transparent**: Reuses `TourRecorder` + `TourRepository` + `TourOptimizationResult` DTO + the optimize `done` response shape rather than inventing parallel machinery. New `TourForcingService` has one job; new `ForceTourRequest` extends `OptimizeTourRequest` to avoid duplicating stop/mode/loop/tour_id rules. Justified additions tracked below. PASS.
- **IV. Robustness as Standard**: Core of the feature — the app must never fail silently. Force persistence failure surfaces `persist_failed` with a log (reusing the optimize pattern); the driver-path audit hardens the one unbounded call; every unknown value stays flagged. PASS.
- **V. Performance with Clarity**: Force = one transactional write, no upstream call. Connect-timeout change is a pure fail-fast improvement. PASS.
- **VI. Consistent, Reusable Front-End Styling**: New field reuses the existing input pattern + `ActionButton`; the "manual" badge and any color use palette role variables only; no one-off hex. PASS.

No unjustified violations.

## Project Structure

### Documentation (this feature)

```text
specs/024-manual-tour-duration/
├── plan.md              # This file
├── research.md          # Phase 0 — decisions (endpoint shape, reuse, driver-path audit)
├── data-model.md        # Phase 1 — entities/fields touched + the forced-tour write
├── quickstart.md        # Phase 1 — how to exercise + verify (API-down walkthrough)
├── contracts/
│   ├── force-tour.md     # NEW endpoint I/O + frontend state/UI contract
│   └── frozen-io.md      # Endpoints that MUST stay unchanged (optimize/geometry/drivers/assign)
├── checklists/
│   └── requirements.md   # Spec quality checklist (done in /speckit-specify)
└── tasks.md             # Phase 2 output (/speckit-tasks — NOT created here)
```

### Source Code (repository root) — in-scope files

```text
routes/
└── api.php                                # + POST tour/force (throttle:tour-read), name tour.force

app/Http/Controllers/
└── TourForceController.php                # NEW — thin: validate → TourForcingService::force → respond (mirror optimize done/failed)

app/Http/Requests/
└── ForceTourRequest.php                   # NEW — extends OptimizeTourRequest rules + required travel_duration_s (bounded)

app/Services/
├── TourForcingService.php                 # NEW — build input-order stops → TourRecorder::record(distance=null, duration=manual) → TourOptimizationResult
├── TourRecorder.php                       # REUSED unchanged (create OR overwrite-in-place path already handles editTourId)
└── OpenStreetRouteClient.php              # HARDEN — add a connect timeout on traceLeg + expose it for the pooled path (fail fast on dead host)

app/Providers/AppServiceProvider.php       # pass route connect_timeout into OpenStreetRouteClient
config/services.php                         # + openstreet.route_connect_timeout (default 10)

app/Repositories/TourRepository.php         # REUSED unchanged (createTourWithStops / overwriteTourWithStops)

resources/js/
├── types/tour.ts                          # OptimizeState 'done' gains `forced?: boolean`; force error/limits constants
├── hooks/use-tour-optimization.ts         # + forceTour(mode, loop, durationMinutes) → POST /api/tour/force, settleDone(forced:true)
├── components/tour/tour-control-bar.tsx    # conditional drive-duration field + Force Tour button, shown only on failure
├── components/tour/result-summary.tsx      # "Manually entered" duration badge when forced
└── pages/tour/optimize.tsx                # thread failed-state → control bar (field/button), pass forced → ResultSummary
```

Out of scope: vendored/starter-kit code; optimize/geometry/assign response shapes (frozen); any DB migration.

**Structure Decision**: Existing single-repo Laravel + Inertia app. The force path is a new thin vertical slice (route → controller → request → service) that **reuses** the persistence layer (`TourRecorder` → `TourRepository`) and the result DTO/response shape of optimize, so the frontend settles a forced tour through the same `done` path. The driver-assignment robustness work is an audit plus one client hardening, not a redesign.

## Complexity Tracking

| Addition | Why needed | Simpler alternative rejected because |
|----------|-----------|--------------------------------------|
| `TourForceController` + `ForceTourRequest` + `TourForcingService` (new slice) | A synchronous, no-upstream write is a distinct responsibility from the async cache/dispatch optimize flow; keeping it out of `TourOptimizationController`/`Service` preserves each class's single job | Bolting a `force()` onto `TourOptimizationService` mixes the "never fire the slow API" orchestration with a plain write — muddies the class the 023 refactor just clarified |
| `ForceTourRequest extends OptimizeTourRequest` | Reuses the 2–10 stops / coord / mode / loop / owned-unassigned `tour_id` validation verbatim, adding only `travel_duration_s` | Copying the rules duplicates validation the constitution forbids duplicating |
| `forced` flag on the done state + result badge | Transparency (FR-014): the dispatcher must always know a duration was hand-entered | Showing a forced tour identically to a measured one hides that the figure is manual — violates "never silently guess" |
| Route client connect timeout | Removes the one place a dead host can stall the driver path for the full read timeout per batch | Leaving it unbounded-on-connect keeps a real (if bounded-by-read-timeout) slow-hang the user explicitly asked to eliminate |
