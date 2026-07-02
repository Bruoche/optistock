# Research: Tour Driver Assignment (+ tour/stop persistence)

## Current data flow (baseline to preserve)

1. **Editing (client)**: stops carry `lat`, `lng`, `durationMinutes` (007); `mode`,
   `loop`, and the selected `date` (011) are client state. `waitTimeS` = Σ stop minutes.
2. **`POST /api/tour/optimize`** (`OptimizeTourRequest`: `coordinates` 2–10, `mode?`,
   `loop?`). `TourOptimizationController` → `TourOptimizationService.optimize`:
   normalize coords → sha256 hash → `TourCache.getTour`. **Cache hit** →
   `200 { status:done, data:{ordered_stops,total_distance_m,total_duration_s} }`.
   **Miss** → claim an active-job lock, `markPending`, dispatch `OptimizeTourJob`,
   `202 { job_uuid }`. A lost claim reuses the running job (no second upstream call).
3. **`OptimizeTourJob`** (`tries=1`, config timeout): calls `OpenStreetTspClient.optimize`.
   Success → release lock, cache 24 h, `markDone`, broadcast `TourOptimized`. Handled
   failure / crash (`failed()`) → release lock, `markFailed`, broadcast
   `TourOptimizationFailed`. Everything logs.
4. **Client settle** (`use-tour-optimization`): `.TourOptimized`/`.TourOptimizationFailed`
   on the private user channel **plus** a 3 s status poll fallback; both funnel to
   `settleDone`/`settleFailed`. `done` state = `{result, mode, loop}`.
5. **`POST /api/tour/geometry`** (`use-tour-geometry`, feature 002): synchronous,
   client-driven, `{stops, mode, loop}` → per-leg road geometry + compounded metrics;
   overrides the straight-line estimate for display. **2-point tours have no geometry.**
   A result-identity token guards against a superseded tour's late response.
6. **Presentation** (`ResultSummary`): "Time on road" (road duration), "Tour duration"
   (road + wait), the date field (011), and `DriverList` (drivers filtered by mode +
   the date's weekday, 006/011). List is currently read-only.

**Key invariants not to break**: the active-job lock/dedup, the broadcast+poll dual
settle, the failure broadcasts (UI never hangs), the geometry identity token, and the
2-point "no geometry" case.

## R1 — Assignment shape: association row vs column

**Decision**: A `driver_tour` association table (`tour_id` **unique**, `driver_id`,
`date`), `Driver belongsToMany Tour` with pivot `date`.

**Rationale**: The user asked for "the date saved in the association table" and, later,
un-assign / re-assign. A standalone association row makes un-assign a row delete and
re-assign a row update, without mutating the tour. The unique `tour_id` encodes the
spec's "one driver per tour" (their "one-to-many driver→tours") while still giving the
pivot an extra `date` column (which a plain `hasMany`/FK column could also do, but less
cleanly for the future history/among-drivers changes).

**Alternatives**: nullable `tours.driver_id` + `tours.assigned_date` columns — simplest
and idiomatic one-to-many, but bakes the assignment onto the tour (worse fit for
re-assignment history and the user's explicit "association table"). Rejected.

## R2 — Where the date lives

**Decision**: On `driver_tour.date`, not on `tours`.

**Rationale**: The date is chosen/edited on the bar **before** assignment and is used
only transiently to filter the driver list (a query param on `GET /api/tour/drivers`).
It becomes durable exactly when the tour is handed to a driver. Putting it on the
assignment avoids a nullable pre-assignment date on `tours` and matches the user's
model. Driver filtering pre-assignment stays a stateless query (unchanged from 011).

## R3 — When/where to persist the tour

**Decision**: A single `TourRecorder.record(...)` (Tour + Stops in a transaction),
invoked from **(a)** `OptimizeTourJob` on success and **(b)** the **cache-hit** branch
of `TourOptimizationService`.

**Rationale**: A tour reaches `done` via exactly those two paths. Persisting inside the
job (which runs once per `job_uuid`) means the broadcast+poll dual-settle cannot create
duplicates. The 24 h cache still dedups the expensive TSP call across users, but
persistence is per-user-result so each optimization gets its own tour with that user's
stop durations. The delicate failure/lock code is untouched — persistence only rides
the success paths.

**Alternatives**: persist in the controller after settle (client-driven) — would need
client-sent data and an idempotency guard against the dual settle. Rejected (more
surface, less trustworthy).

## R4 — Stop durations + ordering into persistence

**Decision**: `OptimizeTourRequest` carries `stops:[{lat,lng,duration_s}]`; stored
`stops.position` is the TSP visiting order; durations are re-attached to ordered stops by
**normalized-coordinate lookup**, not by input index.

**Rationale**: Durations are client-only today; the server needs them to persist. Send
them with the coordinates. An input-index mapping does **not** survive the pipeline:
`CoordinateNormalizer::normalize` rounds (5 dp) and `usort`s the coords before hashing
and dispatch, `OpenStreetTspClient::mapToTour` emits only `{lat,lng,order}` (it drops the
index it holds internally), and the cache-hit path makes no TSP call at all. The one join
key present on **both** done-paths — and on stale, pre-deploy cached tours — is the
normalized coordinate. So the service builds a `normalizedCoord → duration_s` map (same
rounding as the cache key) and `TourRecorder` looks up each ordered stop's coord.
**Duplicate coordinates** (two stops rounding to the same point) are inherently ambiguous
under coordinate matching; consume the map per-coord in order and treat identical-coord
stops as interchangeable for duration. Accepted limitation, not a blocker.

**Alternatives**: (a) rely on a TSP source index — rejected, severed by the normalize
sort + absent on cache hits + absent for stale cached tours. (b) default all durations
server-side and update later — loses the user's edited values at persist time. Rejected.

## R5 — Travel-duration source and timing

**Decision**: Seed `tours.travel_duration_s` with the TSP estimate at persist (null for a
`<3`-point `trivialTour`); the existing geometry trace (given a `tour_id`) updates it to
the road total, persisted in the **controller** after the pure `trace()` returns.

**Rationale**: Spec FR-007 pins the recorded duration to the shown figure (road travel
+ stop durations). Geometry is computed client-driven post-`done`; rather than trust a
client-sent duration, let the geometry endpoint — which already computes the road totals
— persist them onto the tour (in the controller, keeping `TourGeometryService::trace`
pure/reusable). **2-point tours ARE traced** — `useTourGeometry` fires for every done
result and `trace()` handles a 2-stop tour (1 leg open / 2 legs closed) — so the seed is
a transient fallback replaced once the trace resolves, and survives only when the trace
yields null totals (a failed leg). The value is always present (estimate → road) so
projected hours are always computable.

**Alternatives**: move geometry fully server-side into the job — larger refactor,
re-introduces slow upstream calls on the request/job path. Rejected for now. Trust a
client-sent final duration on assign — violates robustness (client-authoritative
figure). Rejected.

## R6 — Threading the tour id to the frontend

**Decision**: Done payloads (`200`, `TourOptimized` broadcast, status poll) include
`tour_id`; `TourResult` gains `id`; geometry + assign use it.

**Rationale**: "The front-end will get these same info from the back-end." A stable id
is the handle the geometry update and the assignment target need.

## R7 — Assignment endpoint + eligibility

**Decision**: `POST /api/tour/{tour}/assign { driver_id, date }`, auth + `throttle:tour-read`.
Server enforces **tour ownership** (`tour.user_id` === user, else `404`), re-validates the
driver is **eligible** (supports the tour's mode AND is scheduled on the date's weekday),
and `updateOrCreate`s the `driver_tour` row.

**Rationale**: Never trust the client — re-check 006/011 rules server-side, and guard the
tour itself: without an ownership check any user could assign another planner's tour. A
non-owned tour returns `404` (not `403`) so a foreign tour id is never confirmed to exist,
mirroring the ownership guard on the geometry-persist path (R5). `updateOrCreate` on
`tour_id` is idempotent and already supports the future re-assign. Ineligible/invalid →
`422`, surfaced (FR-011).

## R8 — Projected hours: server committed + client current

**Decision**: `GET /api/tour/drivers` returns per-driver `assigned_seconds` (Σ over the
driver's tours **assigned for the queried date** of `travel_duration_s + Σ stop.duration_s`).
The frontend adds the **live displayed** current-tour total to get each row's projection.

**Rationale**: The committed load must come from persisted tours (server). The current
tour's total is already on screen (road duration + wait) and is the exact value FR-006/
FR-007 want shown; adding it client-side keeps the projection consistent with the
tour-duration figure and avoids the brief window where the just-persisted tour still
holds the straight-line seed before geometry resolves. The current (unassigned) tour is
not in `driver_tour`, so it is never double-counted.

**Alternatives**: pass `tour_id` to the drivers endpoint and project entirely
server-side — risks showing the straight-line seed until geometry updates, diverging
from the displayed figure. Rejected.

## R9 — Clickable list + confirmation + reset

**Decision**: `DriverList` rows become buttons opening a shared shadcn `AlertDialog`
naming the driver; confirm → assign → `reset()`; cancel → close; one dialog at a time.

**Rationale**: Reuses the app's dialog primitive and the existing `reset()` (already
clears stops + returns to the creation menu). Matches spec US1/US3 and constitution VI.

## R10 — Persistence-failure handling (surface, don't swallow)

**Decision**: Persistence is a first-class failure path. Cache the TSP result **before**
persisting; wrap `record()` on both done-paths; on failure **log + surface** to the user
as a distinct `persist_failed` (cache-hit → `200 { status:'failed', error }`; job →
`TourOptimizationFailed`), never a silent unsaved route nor a generic crash. An unmappable
stop duration is a persist failure (rollback + surface), not a silent `0`. A geometry-
persist failure — a refinement of an already-saved tour — is logged and swallowed (the
tour stays assignable with its seed), and an ignored provided `tour_id` is logged.

**Rationale**: Persistence is new work on the app's most sensitive path (async optimize +
24 h cache + broadcast/poll). Constitution IV demands explicit failure handling + logging
and forbids silent failure; the user additionally requires being **notified** when a route
can't be saved. Caching before persisting means an expensive upstream result is never lost
— a retry is a cache hit that re-attempts only the save. Routing a persist failure through
the existing `failed`/`persist_failed` settle path (not `failed()`'s generic crash)
prevents a *successful* optimization from surfacing as an unexplained error, and keeps an
unsaved route out of the `done` state so it is never offered for assignment (FR-014).
Distinguishing optimize-persist (surfaced) from geometry-persist (logged only) matches
their impact: the former blocks the feature; the latter degrades a duration already seeded.

**Alternatives**: (a) best-effort silent persist — rejected (violates IV + the notify
requirement, and would show an unassignable route with no explanation). (b) let a persist
error escape into `failed()` — rejected (mislabels a successful optimization as a generic
crash and discards the cached TSP result). (c) hard 500 on the cache-hit path — rejected
(regresses the previously write-free read-fast path into an opaque error).

## Regression checklist (carried into tasks)

- Optimize cache-hit **and** job paths both persist exactly one tour; the existing **TSP**
  failure paths unchanged and still broadcast.
- A **persist** failure (either path) is logged and surfaced as `persist_failed` — the
  route never enters `done` and is never assignable; the TSP result stays cached for retry
  (R10 / FR-014).
- Broadcast + poll dual-settle never duplicates a tour.
- Per-stop `duration_s` mapped by normalized coord (not input index) — survives the
  normalize sort, the cache-hit path, and stale cached tours; duplicate-coord ambiguity
  accepted.
- 2-point tours **are** traced → their `travel_duration_s` is updated to the road total
  by the geometry call (the estimate/null seed is only a pre-trace fallback); projection
  still works.
- Geometry identity token still guards superseded tours; adding `tour_id` doesn't change it.
- `OptimizeTourRequest` shape change (`coordinates` → `stops`) updated on client + server
  **and existing optimize tests** together; validation still rejects out-of-range coords
  and <2/>10 stops.
- Assignment re-checks **tour ownership (→404)** AND driver eligibility server-side;
  failure surfaced, no navigation.
