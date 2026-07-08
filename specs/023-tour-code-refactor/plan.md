# Implementation Plan: Tour Code Refactor

**Branch**: `023-tour-code-refactor` | **Date**: 2026-07-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/023-tour-code-refactor/spec.md`

## Summary

A **backend-only, behavior-preserving** refactor of the **whole route-optimization back-end** that is our code — the tour-optimization + edit pipeline, driver availability / workday projection (017–019), tour assignment (012–013), and route geometry (002): controllers, form requests, services, jobs, and API clients. Goal: apply SOLID / clean-code so the code reads naturally and each class keeps its role. Concretely: give each layer one job — **controllers** translate HTTP ⇄ service (validate a request, call one service method, wrap the returned DTO in a response) with no domain logic or direct persistence/queries; **services** hold business logic and return DTOs; new **repositories** own all Eloquent/query data access (today scattered across recorders, controllers, and form requests — e.g. tour/stop persistence, the "find the user's tour" lookup, and the `DB::table('driver_tour')` prior-tour queries inside `DriverController`); **clients** keep owning external-API access (already so). Long methods (e.g. `DriverController::available` ~75 lines, `TourOptimizationService::optimize`, `OptimizeTourJob::handle`) are cut into short, intent-named private methods; names follow verb-methods / noun-variables and read like the business sentence (`$repository->saveTour(...)`, not `$tourPersister->doInsert(...)`) — no misleading words, no readability-hurting abbreviations. Duplication is mutualised only where the business justifies it (no coupling of unrelated code). Endpoint inputs/outputs and all user-visible behavior stay byte-for-byte identical, proven by the existing test suite staying green **unchanged**. Any behavior / robustness / optimization issue noticed along the way is written to `observations.md` and **not acted on**. The **front-end** refactor (shared bar/panel) is a separate, deferred pass — not in scope here.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12 (backend only — no frontend files touched)

**Primary Dependencies**: Laravel (Eloquent, queue, broadcasting), existing OpenStreet API clients

**Storage**: MySQL — no schema, migration, or query-result change; only *where* the Eloquent calls live moves

**Testing**: Pest/PHPUnit (`php artisan test`). The full existing suite (~272 tests) is the correctness guardrail and MUST stay green. Tests are otherwise **untouched**; the single permitted exception is *retargeting* a test's subject (e.g. controller → service/repository) when a responsibility moves, with its logic and assertions unchanged and no new tests added.

**Target Platform**: Containerized web app (feature 008)

**Project Type**: Web application — this plan touches only the Laravel backend

**Performance Goals**: No behavior/perf change. Optimizations that would alter behavior are noted in `observations.md`, not applied.

**Constraints** (from the user, binding):
- Entirely transparent, zero regression; endpoint I/O and behavior identical.
- Tests stay green and unchanged (except the retarget-subject exception above).
- Clean code / SOLID / SRP; short single-purpose functions; verb methods, noun variables; natural, business-close reading.
- Class roles enforced: Controller (request/DTO handling) → Service (logic) → Repository (data obtention) / Client (API obtention).
- Dedup without introducing coupling unsupported by business logic.
- Noticed behavior/robustness/optimization issues are recorded separately, not fixed here.
- **Scope guard**: only our feature code in the tour-optimization pipeline; vendored starter-kit code is never refactored (per project memory).

**Scale/Scope**: Larger. The whole route-optimization backend — 5 controllers, 4 form requests, ~20 services + clients, 1 job — refactored in place, plus new `TourRepository` / `DriverTourRepository` and any small DTOs. Value objects that are already clean get only a naming/comment audit. No routes, response shapes, or migrations change.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Quality First**: The whole point — improves structure while the existing tests prove behavior is preserved. PASS.
- **II. Readable by Default**: Directly targets naming, natural reading, and comment minimalism; narrating comments removed, high-signal ones kept. PASS.
- **III. Simple & Transparent**: SRP + short methods + one job per class. Introducing a repository layer is complexity *justified* by the user's explicit role-separation requirement and by consolidating scattered Eloquent access into one place (also serves "no duplication"). PASS (justification recorded below).
- **IV. Robustness as Standard**: Existing failure handling (persist_failed logging, result invariants) is preserved verbatim; **new** robustness improvements are deferred to `observations.md`, not mixed into this no-regression pass. PASS.
- **V. Performance with Clarity**: No perf change; perf ideas deferred. PASS.
- **VI. Consistent, Reusable Front-End Styling**: N/A — backend-only refactor. PASS (nothing to do).

No unjustified violations. Repository introduction justified under III (single responsibility + de-duplication of data access), per explicit user direction.

## Project Structure

### Documentation (this feature)

```text
specs/023-tour-code-refactor/
├── plan.md              # This file
├── research.md          # Phase 0 — the concrete, file-by-file refactor decisions
├── data-model.md        # Phase 1 — the target layer/role model (no data entities)
├── quickstart.md        # Phase 1 — how transparency is verified
├── observations.md      # Phase 1 — issues noticed but DEFERRED (not acted on)
├── contracts/
│   └── frozen-io.md     # Phase 1 — the endpoint I/O that MUST NOT change
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root) — in-scope backend files

```text
app/Http/Controllers/
├── TourOptimizationController.php     # already thin — keep thin; pass tour_id cleanly
└── TourPageController.php             # move editTour shaping + lookups out to service/repo; keep only HTTP (render/404/redirect)

app/Http/Requests/
└── OptimizeTourRequest.php           # tidy authorize()/rule; route Eloquent lookups through the repository

app/Services/
├── TourOptimizationService.php       # decompose optimize() into short intent-named private methods
├── TourRecorder.php                  # split create/update/attach-stops; drop dead `use App\Models\Stop`
└── TourOptimizationResult.php        # reference DTO pattern (kept; mirror for any new DTO)

app/Jobs/
└── OptimizeTourJob.php               # decompose handle() into callUpstream / cacheAndPersist / broadcast*

app/Http/Controllers/
├── DriverController.php               # move availability orchestration + DB::table queries out to a service + repository; controller only translates
├── TourAssignmentController.php       # move sequence/pivot/unique-violation logic out to an assignment service + repository
└── TourGeometryController.php         # keep thin; ensure it only delegates to TourGeometryService

app/Services/                          # decompose long methods; naming/comment pass; keep roles
├── TravelTimeService.php · TourGeometryService.php · WorkdayEstimator.php
├── WorkdayLegsBuilder.php · TourStartSelector.php · MandatoryBreak.php · TourCache.php
├── OpenStreetTspClient.php · OpenStreetRouteClient.php · PolylineDecoder.php · CoordinateNormalizer.php
└── (value objects) Coordinate · TourSegment · TourStart · WorkdayLeg · WorkdayEstimate · PriorTourLeg · TourOptimizationResult

app/Repositories/                     # NEW — owns Eloquent/query data obtention
├── TourRepository.php                # create tour+stops, update+replace stops, find owned
└── DriverTourRepository.php          # the driver_tour + stops queries now inline in DriverController (prior tours by driver) + assignment writes/sequence
```

Out of scope (never touched): all **front-end** files (the shared bar/panel refactor is deferred — see `observations.md`) and any **starter-kit / vendored** code.

**Structure Decision**: Existing single-repo Laravel app. Adds an `app/Repositories/` folder (`TourRepository`, `DriverTourRepository`) + an `app/DTOs/` folder as needed; extracts availability + assignment services from their controllers; everything else is in-place decomposition and relocation of logic to its correct layer. No routes, no response shapes, no migrations.

## Complexity Tracking

| Addition | Why needed | Simpler alternative rejected because |
|----------|-----------|--------------------------------------|
| `TourRepository` (new data layer) | User-mandated role separation; single-sources the tour/stop Eloquent calls duplicated across `TourRecorder`, `TourPageController`, `OptimizeTourRequest` | Leaving Eloquent inline keeps three copies of "find the user's tour" / persistence across layers — the duplication + role-mixing the refactor removes |
| `DriverTourRepository` (new data layer) | Owns the `DB::table('driver_tour')`/`stops` prior-tour queries now inline in `DriverController`, plus the assignment write + sequence lookup now inline in `TourAssignmentController` | Leaving raw query builders inside controllers keeps data access in the HTTP layer — the exact role violation this refactor targets |
| Extracted availability + assignment services | Move `DriverController::available` orchestration and `TourAssignmentController::assign` logic out of the controllers | Controllers currently hold heavy domain logic; the services give them a home and let the controllers become pure translators |
