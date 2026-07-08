# Data Model: Tour Code Refactor

**No data change.** No entities, fields, migrations, or query results change. What changes is the *layer* each responsibility lives in. This document is the target role model, not a data schema.

## Target layer roles (the "model" being enforced)

| Layer | Responsibility | In-scope classes | May do | May NOT do |
|-------|----------------|------------------|--------|------------|
| **Controller** | Translate HTTP ⇄ service | `TourOptimizationController`, `TourPageController` | Read the validated request, coerce simple inputs, call ONE service method, map the returned DTO/result to a response (JSON / `Inertia::render` / redirect / status code) | Business logic, Eloquent/data access, payload shaping |
| **Form Request** | Validate + authorize the request | `OptimizeTourRequest` | Declare rules, authorize ownership (via repository), map failures to 404/422 | Business logic, direct Eloquent |
| **Service** | Business logic + orchestration | `TourOptimizationService`, edit-shaping service, `TourRecorder` | Orchestrate, apply business rules (duration mapping, cache/dispatch policy), return DTOs | HTTP concerns, direct Eloquent persistence (delegates to repository) |
| **Repository** | Tour/stop data obtention | `TourRepository` (new) | All `Tour`/`Stop` Eloquent reads/writes + the persistence transaction | Business decisions, HTTP |
| **Client** | External API obtention | `OpenStreetTspClient`, `OpenStreetRouteClient` | Call + decode the upstream API | (already correct — unchanged) |
| **DTO / Result** | Immutable data across boundaries | `TourOptimizationResult`, any new `EditTourData` | Carry data + invariant-guarded accessors | Behavior/logic |

## Method-shape rule (applied to every touched method)

- A method reads as a short sentence of named steps; if it "does many things," each thing becomes a private verb-named method:
  `function record(...) { $modeId = $this->resolveModeId(...); $tour = $this->persist(...); $this->attachOrderedStops(...); return $tour; }`
- Methods are **verbs** (`saveTour`, `dispatchOptimization`, `attachOrderedStops`); variables are **nouns** (`$tour`, `$coordinatesHash`, `$durationByCoord`).
- Calls read business-first: `$this->tours->saveTour($userId, $mode, ...)` — actor → action → subject.

## Invariants preserved (must not change)

- Every endpoint's request validation and response shape (see `contracts/frozen-io.md`).
- All broadcast events, cache keys/TTLs, job dispatch args, log messages/levels, and DB writes.
- The `TourOptimizationResult` state machine (`ready` / `pending` / `failed`) and its accessor guarantees.
