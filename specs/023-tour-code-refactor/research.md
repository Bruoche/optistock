# Research: Tour Code Refactor

Every decision below is **behavior-preserving**: same queries, same events, same responses. The existing test suite is the proof. Where a decision only *notices* an issue, it is deferred to `observations.md`.

## Decision 1 — Introduce `App\Repositories\TourRepository` for all tour/stop data obtention

**Decision**: Create one repository that owns the Eloquent access currently scattered:
- `saveTour(...)` / `updateTour(...)` + `replaceStops(...)` — the `Tour::create` / `Tour::update` / `stops()->create` / `stops()->delete` now inside `TourRecorder`.
- `findOwnedTour(int $id, int $userId): ?Tour` and `findOwnedUnassignedTour(...)` — the `Tour::find` + ownership/assignment checks now inline in `TourPageController::edit` and `OptimizeTourRequest`.

The transaction that wraps a tour + its stops stays intact (moved whole into the repository). `TourRecorder` keeps the *business* mapping (ordered stop → duration via `coordinateKey`/`durationFor`) and calls the repository to persist; the controller and request call the repository for lookups instead of touching Eloquent directly.

**Rationale**: The user requires Controller → Service → Repository roles, and the same "get the user's tour" / "persist a tour" logic exists in three layers today. One repository removes that duplication and puts data obtention where it belongs. Names read naturally: `$this->tours->saveTour($userId, ...)`.

**Alternatives considered**:
- *Leave Eloquent inline*: keeps role-mixing and three copies of ownership lookup — rejected (that's the thing we're removing).
- *Fold `TourRecorder` entirely into the repository*: would mix the duration-mapping business rule with data access — rejected; the recorder keeps the rule, the repository keeps the persistence.

## Decision 2 — Decompose `TourOptimizationService::optimize()` into short intent-named steps

**Decision**: Read `optimize()` as a sentence:
```
$request = $this->prepareRequest($stops, $mode, $loop);       // coords, durations, normalized, hash
return $this->serveCachedTour($request, $editTourId)          // cache hit → record + ready|failed
    ?? $this->dispatchOptimization($request, $editTourId);    // miss → claim/reuse job, dispatch, pending
```
Extract private helpers: `prepareRequest`, `serveCachedTour`, `dispatchOptimization`, keeping `recordCacheHit` / `durationByCoord`. Each body stays short and single-purpose.

**Rationale**: Today `optimize()` inlines coordinate building, hashing, cache lookup, job claiming, dispatch, and returns — several responsibilities in one method. Splitting into named steps makes the flow legible without changing it.

**Alternatives considered**: *Leave as one method with comments* — comments narrating steps is exactly what the constitution discourages; named methods are self-evident.

## Decision 3 — Decompose `OptimizeTourJob::handle()` into named steps

**Decision**: Split the ~50-line `handle()` into: `optimizeUpstream()` (call client, on failure release+markFailed+broadcast, return null), `persistAndBroadcast($tour)` (cache, record via recorder, markDone, broadcast; on save failure markFailed+broadcast). `handle()` becomes a short orchestration. Logging and event dispatch stay identical.

**Rationale**: One method currently owns the upstream call, cache write, persistence, and both failure branches. Short named steps make each outcome obvious; behavior (events, logs, cache) is unchanged.

## Decision 4 — Split `TourRecorder::record()`; drop dead import

**Decision**: `record()` reads as `resolveModeId → (create|update) tour → attach ordered stops`. Keep `createTour`/`updateExistingTour(replaceExistingTour)` and add `attachOrderedStops(...)` for the loop; the actual DB writes delegate to `TourRepository` (Decision 1). Remove the unused `use App\Models\Stop;` (dead code, already flagged by the linter). Method names are verbs; the queue-of-durations map stays.

**Rationale**: SRP + dead-code removal (FR-005). The mapping rule (a coordinate → its next queued duration) is business logic and stays here; persistence moves to the repository.

## Decision 5 — Move `TourPageController::edit` shaping into a service + keep only HTTP in the controller

**Decision**: The controller keeps the HTTP decisions (404 for a foreign tour, redirect for an assigned one, `Inertia::render`). The "produce the editable tour payload" work — loading `deliveryMode`/`stops` and mapping each stop to `{lat, lng, duration_minutes}` in position order — moves to a service method returning a small DTO/array (e.g. `EditTourData`). Ownership/assignment lookups use `TourRepository`. `create()` stays trivial.

**Rationale**: Controllers must not shape domain payloads or query Eloquent. The mapping is business logic; the guard-to-HTTP-status mapping is the controller's job and stays. Output JSON shape is unchanged.

**Alternatives considered**: *Move the 404/redirect into the service* — HTTP status/redirect is a transport concern and belongs in the controller; only the data shaping moves.

## Decision 6 — Tidy `OptimizeTourController` + `OptimizeTourRequest`

**Decision**: `TourOptimizationController::optimizeTour` stays thin — read validated inputs, coerce `tour_id` once, call the service, map the `TourOptimizationResult` to the response (it already does this; keep it clean). `OptimizeTourRequest`: route its `Tour::find` ownership check and the `unassignedTourRule` through `TourRepository`; keep the exact 404 (foreign/missing) and 422 (assigned) outcomes. No rule semantics change.

**Rationale**: Keep the controller a pure translator; remove direct Eloquent from the request. Behavior (status codes, messages) identical.

## Decision 7 — De-duplication pass (business-justified only)

**Decision**: Consolidate only genuinely-shared logic: the tour/stop persistence + ownership lookups (→ `TourRepository`, Decision 1). Keep already-shared helpers (`TourOptimizationService::persistError()`, `TourRecorder::coordinateKey`). Do **not** merge things that are only superficially alike (e.g. different controllers' response shaping) — that would couple unrelated endpoints.

**Rationale**: FR-003 — mutualise real duplication, avoid coupling unsupported by business logic.

## Decision 8 — Naming + comment pass across the touched files

**Decision**: Ensure methods are verbs, variables are nouns, and calls read like the business sentence (`$this->tours->saveTour(...)`, `$this->cache->getTour(...)`). Remove comments that narrate now-self-evident (post-decomposition) steps; keep only comments recording non-inferable constraints (e.g. the "cache before persist so a save retry re-tries only the save" rule, the queue-per-coordinate duplicate-coordinate rule).

**Rationale**: FR-004 + Constitution II.

## Decision 9 — Test handling

**Decision**: Run the full existing suite; it must stay green with **no edits**. The only permitted change is *retargeting* a test whose subject moved (e.g. a persistence assertion that currently drives the recorder now driving `TourRepository`), keeping the same setup/assertions and adding nothing. Prefer zero test changes; most refactors here (decomposition, renames, delegation) leave public seams intact so tests don't move at all. No new tests (this is a no-behavior-change pass; characterization is already provided by the existing suite).

**Rationale**: The user's rule — tests untouched/green, retarget-only when a responsibility moves, no new test logic.
