# Research: Edit Tour

## Decision 1 — Reuse the optimize endpoint with an optional `tour_id` (no new endpoint)

**Decision**: `POST /api/tour/optimize` accepts an optional `tour_id`. When present and valid, the persistence step updates that tour in place; when absent, it creates a new tour (today's behavior). The id is threaded through `TourOptimizationService::optimize → (cache-hit) recordCacheHit | (queued) OptimizeTourJob → TourRecorder::record`.

**Rationale**: Editing differs from creating only at the final persistence step — the normalize → cache → dedup → optimize pipeline is identical. A separate "update" endpoint would duplicate all of that orchestration, violating the "avoid duplicated code" constraint and Constitution III/§Additional Constraints (no duplicate logic). One extra optional parameter keeps the change additive and the pipeline single-sourced.

**Alternatives considered**:
- *New `PATCH /api/tour/{tour}` endpoint*: would re-implement the whole optimize flow or delegate back to it anyway — pure duplication.
- *Delete + recreate the tour*: breaks the "same identity / no duplicate" requirement (SC-002, FR-008) and would churn ids that the assignment flow later references.

## Decision 2 — Update = same tour row, replace its stops in one transaction

**Decision**: In `TourRecorder::record`, when `editTourId` is set, load that tour, update its `delivery_mode_id`, `loop`, `travel_duration_s`, `total_distance_m`, delete its existing `stops`, then recreate the ordered stops — all inside the existing `DB::transaction`.

**Rationale**: The optimized stop set (order, coordinates, durations) is fully recomputed on every optimize; a positional diff/merge would be more code for no benefit. Replace-in-transaction keeps the write atomic and the tour's identity stable. Reuses the exact stop-creation loop already in `record`.

**Alternatives considered**:
- *Diff and upsert stops by position*: more complex, no user-visible gain, and stop count/order can change on edit.
- *Update outside a transaction*: a mid-replace failure could leave a tour with a partial stop set — rejected (Robustness).

## Decision 3 — Validate `tour_id` for ownership AND unassigned in the request

**Decision**: `OptimizeTourRequest` validates `tour_id` (when present) as: exists, `user_id` = caller, and has no `driver_tour` assignment. A foreign/nonexistent id → 404 (never confirm a foreign id exists), an assigned tour → 422 (not editable). Mirrors the ownership pattern in `AssignTourRequest`.

**Rationale**: Editing is gated to unassigned tours (FR-009). Server-side enforcement means the client's Edit affordance is never trusted. 404-for-foreign matches the established convention (`AssignTourRequest::failedAuthorization`).

**Alternatives considered**:
- *Authorize in the controller/service*: scatters the guard; the request object already owns tour authorization elsewhere.
- *Allow editing an assigned tour*: out of scope ("before attribution") and would desync a recorded assignment's start/end stops.

## Decision 4 — Hydrate the edit page from a server-supplied prop keyed by tour id path param

**Decision**: The optimize page is served by a new `TourPageController` with two entry points: the plain page (`editTour = null`) and `tour/{tour}/edit` which loads the owned, unassigned tour and passes an `editTour` prop = `{ id, mode, loop, stops: [{ lat, lng, duration_minutes }] in position order }`. The React page seeds `useTourOptimization` from it and remembers the `editTourId`.

**Rationale**: Edit is a full page navigation (`router.visit`), so in-session React state is gone — the parameters must come from the server. Serializing straight from the tour's own persisted stops/mode/loop makes the restore reliable (SC-003) and needs no new API: `Route::inertia` is simply swapped for a controller that can pass DB-derived props. Delivery duration is converted seconds→minutes to match the client `Stop` shape.

**Alternatives considered**:
- *Keep `Route::inertia` and fetch the tour via a separate XHR on mount*: an extra endpoint + a loading state for data the page already needs at first paint.
- *Carry state across the navigation client-side*: fragile, and a deep-linked `/tour/{id}/edit` would have nothing to restore.

## Decision 5 — Date is not restored (not persisted on an unassigned tour)

**Decision**: The `editTour` prop omits date; the page keeps its default (today). Mode and loop ARE restored (they live on the tour); date lives only on the `driver_tour` pivot, which an unassigned tour has none of.

**Rationale**: There is no per-tour date to restore before assignment — inventing one would misrepresent the data. FR-005 lists "options" restored from the tour; date is an assignment-time choice, captured in the spec's Assumptions. Keeps scope minimal and truthful (no schema change).

**Alternatives considered**:
- *Add a `date` column to `tours`*: schema change for a value the assignment step already owns — rejected as scope creep.

## Decision 6 — Queued-edit safety: a vanished target surfaces as `persist_failed`

**Decision**: For the async path, `editTourId` is validated at request time but the update happens later in `OptimizeTourJob`. If the tour no longer exists / became assigned when the recorder runs, the update throws, is caught, logged, and broadcast as `persist_failed` (the existing failure channel) — it does NOT silently fall back to creating a new tour.

**Rationale**: Robustness — no silent create, every failure logged with context (Constitution IV). Reuses the recorder's existing try/catch → `persistError()` path already wired in `OptimizeTourJob`.

**Alternatives considered**:
- *Fall back to create on missing target*: would produce the duplicate the feature exists to prevent.
