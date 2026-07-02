# Implementation Plan: Tour Driver Assignment (+ tour/stop persistence)

**Branch**: `012-tour-driver-assignment` | **Date**: 2026-07-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/012-tour-driver-assignment/spec.md`, building on
features 001 (optimization), 002 (road geometry), 006 (driver list), 007 (stop durations),
011 (date/weekday filtering).

## Summary

Two coupled changes:

1. **Persist tours + stops.** Optimized tours are today transient — computed by an
   async job, cached 24 h, and held only in client state; road geometry and stop
   durations live client-side. This feature persists a **`tours`** row (mode, loop,
   travel duration) and its ordered **`stops`** (coordinates + per-stop duration +
   position) at the moment an optimization reaches `done`, and threads the tour's id
   back to the frontend so subsequent steps refer to a real record.

2. **Assign a tour to a driver.** On the presentation phase the driver list becomes
   clickable: clicking a driver opens a confirmation, and confirming records a
   **`driver_tour`** assignment (driver + the selected date) and returns the manager
   to a cleared creation menu. Each driver row shows their **projected working hours**
   for the date — their committed assigned time plus this tour.

The design is deliberately shaped so the **later** un-assign / re-assign and
route-editing features drop in without rework: the assignment is a standalone
association row (not a column baked onto the tour), and stops are ordered rows that
can be mutated/re-ordered.

## Technical Context

**Stack**: Laravel 12 (PHP) + React 19 + Inertia + Tailwind v4 + shadcn/ui. Queue +
Reverb broadcast for the async optimization; MySQL/SQLite storage.

**Existing data flow (must be preserved — see research.md for the full trace)**:
- `POST /api/tour/optimize` → `TourOptimizationService` normalizes coords, hashes,
  and either serves a **cache hit synchronously** (`200 done`) or **claims + dispatches
  `OptimizeTourJob`** (`202 pending`). The job calls the slow OpenStreet TSP client,
  caches the result 24 h, records job status, and broadcasts `TourOptimized`
  (with a status-poll fallback). Failure paths release the active-job lock, mark
  failed, and broadcast `TourOptimizationFailed` — the UI never hangs.
- `POST /api/tour/geometry` (feature 002) is a **separate, synchronous, client-driven**
  call that returns road-accurate per-leg geometry + compounded metrics; 2-point tours
  have no geometry. The client overrides the straight-line estimate with these.
- Stop durations (007) and the selected date (011) are **client-side only** today;
  the optimize request carries only `coordinates`, `mode`, `loop`.

**Testing**: PHPUnit (`Tests\TestCase` + `RefreshDatabase`); Vitest + Testing Library.

**Project Type**: web app (Laravel + React SPA).

**Performance/Scale**: trivial writes (one tour + ≤10 stops per optimization; one
assignment row); the projected-hours query is a small indexed aggregate per driver.

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — new tables/models/relations, the persistence hooks on
  both done-paths, the assignment endpoint, the projected-hours aggregate, and the
  clickable list + confirmation are all covered: backend Feature tests (optimize
  persists a tour+stops on cache-hit **and** job paths; geometry updates the tour's
  travel duration; assign creates/rejects; drivers payload includes committed
  seconds; auth) + Unit tests (tour total-duration accessor; assignment relation) +
  frontend tests (clickable row → confirm → assign → reset; cancel; projected hours =
  committed + current). PASS.
- **II/III. Readable & Simple** — reuses the established lookup+pivot pattern (006/011)
  for `driver_tour`; persistence is a single service method invoked from the two
  existing done-paths (no new mechanism in the delicate cache/broadcast code); one thin
  assignment controller over a model method. PASS.
- **IV. Robustness** — tour+stops written in a **DB transaction** (no partial tours);
  persistence rides the two success paths, and **its own failure is handled explicitly,
  logged, and surfaced to the user** (D10 / FR-014) — never a silent unsaved route nor a
  successful optimization masquerading as a generic crash. Assignment validates the tour
  is **owned** by the user (else 404) and the driver is eligible (mode + the date's
  weekday), is **idempotent** (`updateOrCreate` on `tour_id`, with the unique-constraint
  race caught and treated as success — RB5), and returns a surfaced error on failure
  (FR-011) that the client toasts without navigating away; the broadcast/poll
  double-delivery cannot duplicate a tour because persistence happens once inside the job.
  An unmappable stop duration fails the persist loudly rather than writing a silent 0
  (RB3); a geometry-persist skip is logged (RB4). Every new failure path logs. PASS.
- **V. Performance with Clarity** — indexed FKs; the projected-hours sum is a single
  grouped aggregate over `driver_tour ⋈ tours ⋈ stops` filtered by date; stops
  eager-loaded where needed (no N+1). PASS.
- **VI. Consistent, Reusable Styling** — the confirmation uses the shared shadcn dialog
  primitive; the clickable row and hours reuse role-named colors + the existing
  duration formatter; no raw hex. PASS.

No violations. (Complexity Tracking omitted.)

## Decisions

- **D1 — Three new tables.**
  - `tours`: `id`, `user_id` (FK, planner/owner), `delivery_mode_id` (FK →
    `delivery_modes`), `loop` (bool), `travel_duration_s` (unsigned int, nullable),
    `total_distance_m` (unsigned int, nullable), timestamps.
  - `stops`: `id`, `tour_id` (FK, cascade), `latitude` / `longitude` (decimal),
    `duration_s` (unsigned int — the per-stop delivery time, 007), `position`
    (unsigned int — the optimized visiting order, 0-based), timestamps.
  - `driver_tour` (assignment association): `id`, `tour_id` (FK, cascade, **unique**),
    `driver_id` (FK, cascade), `date` (date), timestamps.
  Relationships: `Tour belongsTo DeliveryMode`, `belongsTo User`, `hasMany Stop`
  (ordered by `position`); `Stop belongsTo Tour`; `Driver belongsToMany Tour` via
  `driver_tour` with pivot `date`. The **unique `tour_id`** encodes "one driver per
  tour" (the spec's "one-to-many driver→tours") while keeping the date in the
  association row as the user asked.

- **D2 — The tour's date lives on the assignment, not on `tours`.** The selected date
  (011) is chosen/edited on the bar *before* assignment and is used transiently to
  filter drivers (a query param — unchanged). It becomes durable only when the tour is
  assigned, so it is stored on `driver_tour.date` (the day the tour is assigned for).
  This matches the user's "date saved in the association table" and avoids a nullable,
  pre-assignment date column on `tours`. (Rationale detailed in research R2.)

- **D3 — Persist at `done`, in one place, on both done-paths.** A single
  `TourRecorder` service method creates the `Tour` + `Stop` rows in a transaction. It
  is called from **(a)** `OptimizeTourJob` after a successful TSP call and **(b)** the
  synchronous **cache-hit** branch of `TourOptimizationService`. Persistence is
  per-user-result (the 24 h geometry cache still dedups the expensive TSP call across
  users, but each optimization yields its own tour row with that user's stop
  durations). Because the job runs once per `job_uuid` and persistence lives inside it,
  the broadcast+poll double-delivery can never create duplicate tours. (research R3)

- **D4 — Stop durations flow into the optimize request; positions come from the TSP
  order; durations are re-attached by normalized coordinate.** `OptimizeTourRequest`
  gains a `stops` array (`lat`, `lng`, `duration_s`) — replacing the bare `coordinates`
  — so the server can persist each stop's duration. **A TSP input index cannot carry the
  duration through:** `CoordinateNormalizer` *rounds (5 dp) and re-sorts* the coordinate
  set before hashing/dispatch, and the cache-hit path makes no TSP call at all, so by the
  time `ordered_stops` exist their positional link to the request's `duration_s` is gone.
  Instead the service builds a **normalized-coordinate → `duration_s` map** (round each
  request coord to `CoordinateNormalizer::PRECISION`, same as the cache key) and threads
  it to `TourRecorder`, which looks up each ordered stop's coord in the map. This works
  uniformly on **both** done-paths and survives stale (old-shape) cache entries — no
  reliance on any TSP-returned source index. **Duplicate coordinates** (two stops rounding
  to the same point) are a known ambiguity: consume the map per-coord in order and accept
  that identical-coord stops are interchangeable for duration purposes. (research R4)

- **D5 — Travel duration is the road-accurate value, finalized by the geometry call.**
  Spec FR-007 requires the recorded duration to equal what the presentation shows
  (road travel + stop durations). At persist time `travel_duration_s` is seeded with
  the TSP estimate (null for a <3-point `trivialTour`); the existing `POST /api/tour/geometry`
  call gains an optional `tour_id` and, when present + owned, the **controller** (after
  the pure `trace()` returns) **updates that tour's `travel_duration_s` +
  `total_distance_m`** to the road totals. **2-point tours ARE traced** —
  `useTourGeometry` runs for every done result and `TourGeometryService::trace` handles a
  2-stop tour (1 leg open / 2 legs closed) — so the seed is only a transient pre-geometry
  fallback, replaced once the trace resolves. The seed survives *only* when the trace
  cannot produce totals (a leg failed → `total_duration_s` null). The value is therefore
  always present and, once geometry resolves, server-authoritative — no client-sent
  duration is trusted. (research R5)

- **D6 — Frontend gets the tour id from the backend and threads it through.**
  The done payload (`200`, the `TourOptimized` broadcast, and the status poll) gains
  `tour_id`; `TourResult` gains `id`. The geometry call sends `tour_id`; the assign
  call targets it. (research R6)

- **D7 — `POST /api/tour/{tour}/assign`.** Body `{ driver_id, date }`. Auth-guarded,
  `throttle:tour-read` (read-tier limiter reused; the write is trivial and rare).
  **Ownership: the bound tour MUST belong to the requesting user** (`tour.user_id` ===
  `request user`) — otherwise `404` (do not leak another planner's tour id). Mirrors the
  ownership guard on the geometry-persist path (D5). Then validates the driver exists and
  is **eligible** for the tour (supports the tour's mode **and** is scheduled on the
  date's weekday — re-checking 006/011 server-side, never trusting the client list).
  `updateOrCreate` on `tour_id` (idempotent now; forward-compatible with re-assignment).
  Returns `200` on success, `422` on an ineligible/invalid driver, `404` on an unknown or
  non-owned tour, surfaced to the client. (research R7)

- **D8 — Projected hours = server committed seconds + client current-tour duration.**
  `GET /api/tour/drivers` (006/011) gains, per driver, `assigned_seconds` — the sum of
  `COALESCE(travel_duration_s, 0) + Σ stop.duration_s` over the tours **assigned to that
  driver for the queried date**. The `COALESCE` matters: a committed tour with an
  **unknown** travel duration (null — no routing call / API failure, FR-012) still
  contributes its stop time, so the aggregate never turns null. This is deliberately
  distinct from the Tour `total_duration_s` accessor, which *propagates* null for
  per-tour detection of the unknown state. The frontend adds the **live displayed**
  current-tour total (road duration + wait, already on screen) to get each row's
  projected hours. This keeps the shown projection consistent with the on-screen
  tour-duration figure and sidesteps the brief window where the server tour still holds
  the estimate seed before geometry resolves. The current (unassigned) tour is never
  double-counted. (research R8)

- **D9 — Clickable row + shared confirmation dialog; reset on success.** `DriverList`
  rows become buttons; clicking opens a shadcn `AlertDialog` naming the driver.
  Confirm → `POST assign` → on success call the existing `reset()` (clears stops +
  returns to the creation menu). Cancel closes the dialog, list intact. A failed assign
  toasts and stays put (FR-005/FR-011). Only one dialog at a time. (research R9)

- **D10 — Persistence failure is a surfaced, notified failure — never a silent unsaved
  route (FR-014).** Persistence is new work on the two done-paths, so its own failure must
  be handled explicitly (constitution IV), and the user must be told (per requirement).
  - **Ordering (both paths):** cache the TSP result *before* persisting, then persist, so
    an expensive upstream result is never lost — a retry becomes a cache hit that
    re-attempts only the save.
  - **Job path** (`OptimizeTourJob`): `record()` runs after `putTour`, wrapped so a
    persistence exception is **caught, logged (`error`) with job/user context, and
    broadcast as a failure** (`TourOptimizationFailed`, code `persist_failed`) instead of
    escaping into `failed()` and masquerading as a generic crash. A DB error therefore
    never flips a *successful* optimization into a confusing generic failure — it is a
    clearly-labelled persist failure the client toasts.
  - **Cache-hit path** (`TourOptimizationService::optimize`): `record()` is wrapped; on
    failure the service returns a **failed** result and the controller responds
    `200 { status: 'failed', error: { code: 'persist_failed', … } }` (the same shape the
    poll/broadcast settle already understands), which the client toasts. The read-fast
    path is not silently regressed into a raw 500.
  - **Duration-map integrity (RB3):** if an ordered stop's coordinate has **no** entry in
    the `normalizedCoord → duration_s` map (a real invariant break, not a duplicate-coord
    collision), that is treated as a persist failure (transaction rolled back, logged,
    surfaced) rather than silently writing `duration_s = 0`.
  - **Not offered for assignment:** without a persisted `tour_id` the frontend never
    reaches the clickable-driver step (no id to assign), so an unsaved route cannot be
    handed to a driver.
  - **Geometry-persist failure (RB4):** the road-total write is a refinement of an
    *already-persisted* tour (seed still present, tour still assignable), so a failure
    there is **logged (`warning`) and skipped**, not surfaced — the trace still returns.
    A provided `tour_id` that is unknown/unowned/null-total is likewise logged when
    ignored (no silent skip). (research R10)

## Project Structure (feature-specific)

Backend — **new**:
- `database/migrations/2026_07_01_000002_create_tour_tables.php` — `tours`, `stops`,
  `driver_tour`.
- `app/Models/Tour.php` — relations (`deliveryMode`, `user`, `stops`, `driver`);
  `total_duration_s` accessor that **propagates null** (`travel_duration_s === null ? null
  : travel_duration_s + stops.sum(duration_s)` — FR-012, never `?? 0`).
- `app/Models/Stop.php` — `belongsTo(Tour)`.
- `app/Services/TourRecorder.php` — `record(userId, mode, loop, orderedStops[], durationByCoord, distance, duration): Tour`
  in a transaction; reused by the job + the cache-hit path; throws on an unmappable stop
  duration (RB3) — callers log + surface (D10).
- `app/Http/Controllers/TourAssignmentController.php` — `assign(AssignTourRequest, Tour)`.
- `app/Http/Requests/AssignTourRequest.php` — `driver_id` exists + eligible, `date` required|date.
- `database/factories/TourFactory.php`, `StopFactory.php` (+ demo hook if useful).

Backend — **change**:
- `app/Http/Requests/OptimizeTourRequest.php` — accept `stops` (`lat`,`lng`,`duration_s`)
  in place of bare `coordinates` (keep coordinate validation; add `duration_s`).
- `app/Services/TourOptimizationService.php` — carry stop durations; on **cache hit**,
  record the tour; a persist failure → log + return a **failed** result (`persist_failed`,
  D10). Pass durations through to the job on miss.
- `app/Services/TourOptimizationResult.php` — add a **`failed(TourError)`** state alongside
  `ready`/`pending` (surfaces a cache-hit persist failure).
- `app/Http/Controllers/TourOptimizationController.php` — return `data.id` on done; map a
  `failed` result to `200 { status:'failed', error:{ code:'persist_failed' } }`.
- `app/Jobs/OptimizeTourJob.php` — carry stop durations; `putTour` first, then record the
  tour; a persist failure → log (`error`) + `markFailed`/broadcast `persist_failed` (not a
  generic crash). Include `tour_id` in the done status + `TourOptimized` payload. Existing
  TSP failure paths unchanged.
- `app/Events/TourOptimized.php` — include `tour_id` in the broadcast `data`.
- `app/Http/Controllers/TourGeometryController.php` + `TourGeometryRequest` — optional
  `tour_id`; when present + owned + non-null totals, the **controller** persists road
  totals (logs an ignored/failed persist; RB4). `TourGeometryService::trace` stays **pure**
  (unchanged).
- `app/Http/Controllers/DriverController.php` + `Driver` model — add `assigned_seconds`
  (committed load for the queried `date`) to the drivers payload.
- `routes/api.php` — `POST tour/{tour}/assign` (`throttle:tour-read`).

Frontend — **new**:
- `resources/js/components/tour/assign-driver-dialog.tsx` — confirmation over shadcn AlertDialog.
- `resources/js/hooks/use-assign-driver.ts` — `POST /api/tour/{id}/assign`.

Frontend — **change**:
- `resources/js/types/tour.ts` — `TourResult.id`; `Driver.assignedSeconds`; a
  `projectedHours` helper (committed + current total); `'persist_failed'` in `TourError.code`.
- `resources/js/hooks/use-tour-optimization.ts` — send `stops` (with durations); carry
  `tour_id` into the `done` state; branch the `200` on `status` (`done` vs `failed`) so a
  `persist_failed` save error toasts and never enters `done` (D10/FR-014).
- `resources/js/hooks/use-tour-geometry.ts` — send `tour_id` with the trace.
- `resources/js/hooks/use-tour-drivers.ts` — map `assigned_seconds`.
- `resources/js/components/tour/driver-list.tsx` — clickable rows + projected hours +
  wire the dialog; needs the current tour total + a tour id + an `onAssigned` callback.
- `resources/js/components/tour/result-summary.tsx` + `pages/tour/optimize.tsx` — pass
  the tour id, the current tour total, and an `onAssigned` (→ `reset()`) down.

Tests: `tests/Feature/TourPersistenceTest.php`, `TourAssignmentTest.php`,
`DriverAvailabilityTest.php` (extend: `assigned_seconds`); `tests/Unit/TourTest.php`;
frontend `assign-driver-dialog.test.tsx`, `driver-list.test.tsx` (extend).

Out of scope (designed-for, not built): un-assign / re-assign UI; editing a persisted
route; per-driver contracted daily-hours limits; cleanup of never-assigned tours;
breaks / inter-tour travel time.

## Flow (assignment path)

1. Editing: stops (lat/lng + duration) + mode + loop + date on the bar.
2. `optimize()` → `POST /api/tour/optimize { stops:[{lat,lng,duration_s}], mode, loop }`.
3. Cache hit → service records the tour → `200 { status:done, data:{ id, ordered_stops, … } }`.
   Miss → job runs TSP, caches, records the tour, broadcasts `done` with `tour_id`.
   **Persist failure (either path)** → logged + surfaced as `persist_failed` (cache-hit:
   `200 { status:failed, error }`; job: `TourOptimizationFailed`); the client toasts and
   the route never enters `done`, so it cannot be assigned (D10/FR-014).
4. `done` state holds `result.id`. `useTourGeometry` posts the trace **with `tour_id`**;
   the server updates `travel_duration_s` to the road total.
5. Presentation: `useTourDrivers(mode,date)` → drivers + `assignedSeconds`. Each row
   shows projected = `assignedSeconds + currentTourTotalS`. Rows are clickable.
6. Click a driver → confirmation dialog → confirm → `POST /api/tour/{id}/assign
   { driver_id, date }` → server validates **ownership + eligibility**, `updateOrCreate`
   assignment (unique-`tour_id` race caught as idempotent success).
7. Success → `reset()` (cleared creation menu). Failure → toast, stay on presentation.
   Cancel → dialog closes, list intact.

## API contracts (this run)

- `POST /api/tour/optimize` — request now `{ stops:[{lat,lng,duration_s}], mode?, loop? }`;
  done payloads gain `tour_id` / `data.id`; a persist failure returns
  `200 { status:'failed', error:{ code:'persist_failed' } }` (D10/FR-014).
- `POST /api/tour/geometry` — optional `tour_id`; persists road totals onto the tour
  (controller-side; ignored/failed persist logged, trace unaffected).
- `GET /api/tour/drivers?mode&date` — each driver gains `assigned_seconds`.
- `POST /api/tour/{tour}/assign` — `{ driver_id, date }` → `200` | `422` | `404` (unknown or
  non-owned tour).

See `contracts/tour-persistence.md` and `contracts/tour-assignment.md`.

## Design Artifacts (this run)

- `research.md` — the current data-flow trace; the nine decisions with alternatives
  (association table vs column; duration source/timing; persistence points; idempotency;
  projected-hours placement).
- `data-model.md` — `tours`, `stops`, `driver_tour`; fields, relations, the
  `total_duration_s` accessor, and forward-compat notes for un-/re-assign + edit.
- `contracts/tour-persistence.md` — the optimize/geometry/drivers changes.
- `contracts/tour-assignment.md` — the assign endpoint + the confirmation/clickable UI.
- `quickstart.md` — persist-on-optimize + assign-and-return + projected-hours checks.

---

Generated by speckit.plan on 2026-07-01
