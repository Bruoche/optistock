# Research: Driver Management Page

Phase 0 — decisions behind the plan. Every "NEEDS CLARIFICATION" from the spec/plan is resolved here.

## Decision 1 — One day-data endpoint feeds the whole page

**Decision**: `GET /api/driver/{driver}/day?date=YYYY-MM-DD` returns, in one response: driver identity, the day's tours in running order (each with stops + total/driven/stop durations), the day's workday summary (total/driven/stop/break, each possibly null), and the neutral drawable legs of the day. Warehouses + all delivery modes for the identity editor ship as Inertia page props on the initial `GET /driver/{driver}` render (they don't change per day).

**Rationale**: The user directed "a request giving all the data of the tours of the day." Collapsing to one read keeps the front-end fetch/cancel logic identical to `use-tour-drivers` (one effect, one cancel flag, one loading gate) and lets `TravelTimeService::preload` batch every connection of the day in a single capped concurrent pass — no per-leg N+1.

**Alternatives considered**: (a) Separate endpoints per widget — rejected: multiplies loading states and routing batches. (b) Push everything through Inertia props on day change — rejected: day switching would need full Inertia visits, losing the snappy client re-fetch + cancellation the spec's reactivity requirements (FR-039/040) call for.

## Decision 2 — Day load reuses stored entry/exit; recompute only on reorder

**Decision**: On read, each tour's entry/exit come from its `driver_tour` pivot (`start_/end_lat/lng`), set at assignment (012) or the last reorder. The day legs + totals are computed from those fixed points using the existing `PriorTourLeg`/`WorkdayEstimator` path. No `TourStartSelector` on read.

**Rationale**: The stored points already represent the optimal choice for the current order; recomputing on every page view would spend routing calls to reproduce them and could drift if the API is degraded. CL-001 scopes recompute to the *save* of a new order.

**Alternatives considered**: Recompute-on-read — rejected: needless routing, non-deterministic display under API flakiness.

## Decision 3 — Reorder: recompute entry/exit, block on routing failure, force-save escape hatch

**Decision**: `POST /api/driver/{driver}/tour-order` with `{ date, tour_ids: [...] , force?: bool }`.
- Normal save: chain the new order — incoming point = warehouse, then each tour's `TourStartSelector::select(incoming, tour, mode)`, incoming ← selected end. Re-measure warehouse→first, between, last→warehouse. Persist new `sequence` + selected `start/end` per pivot row in a transaction.
- If any connection needed to *select* a start (i.e. all of a tour's start candidates unroutable) or to measure the chain is unroutable → **422**, persist nothing, return which leg failed.
- `force:true`: persist the new `sequence` and each tour's entry/exit chosen by the selector's routing-independent fallback (lowest-position candidate for a one-way, first stop for a loop) — no full re-optimization. Logged at warning (Constitution IV), mirrors 024's manual fallback.

**Rationale**: The clarification: "legs are necessary to select the entry/exit… recomputed to stay well optimized. Add a force-save on failure." A degraded routing service must never make a day permanently un-reorderable, but the *default* must produce the well-optimized result.

**Conflict detection (FR-034)**: the submitted `tour_ids` (as a set) must equal the driver's current `driver_tour` tour ids for that date. Mismatch → **409**; the client refetches the day. Prevents writing a stale order after a concurrent assign/delete.

**Alternatives considered**: Save order only, no recompute (rejected by CL-001). Always-degrade save (rejected by planning Q — loses optimization). Hard block with no force (rejected — reintroduces the exact dead-end 024 was built to avoid).

## Decision 4 — Driver-detail update is multipart with a warehouse-change advisory

**Decision**: `PATCH /api/driver/{driver}` (multipart to allow an image file). Fields: `name` (required, non-empty), `mode_ids`/`modes` (≥1), `warehouse_id` (must exist), optional `image` upload. Server validates, stores the image on the `public` disk (same as existing driver images), `sync`s modes, updates the row. The **Update** button is enabled only when the on-screen values differ from the loaded values (client dirty-check). A **warehouse change** shows a fixed `ConfirmDialog` advisory before the request fires.

**Rationale**: Clarification simplified the warning to "always warn about the risk from changing warehouse" — a static advisory, not a computed per-date impact report. This drops the preflight endpoint entirely: no extra round-trip, no cross-date query. Existing assignments are left intact and recompute when their day is next viewed (FR-007b), consistent with the app's degrade-not-lie stance.

**Alternatives considered**: Server preflight enumerating affected dates — rejected by the clarification (over-engineered for the need). JSON (base64 image) — rejected: multipart is the idiomatic Laravel upload and avoids payload bloat.

**Mode-removal note**: removing a mode a driver is assigned tours in is *allowed* (no block). Those assignments simply persist; the driver page recomputes/marks them when viewed. Only the warehouse change is gated by the advisory, per the clarification.

## Decision 5 — Drag reorder via `@dnd-kit/sortable`

**Decision**: Add `@dnd-kit/core` + `@dnd-kit/sortable`. Vertical sortable list, drag handle on the row's far left, `restrictToVerticalAxis`. Single-tour days render inert handles.

**Rationale**: No DnD library exists in the repo. `@dnd-kit` is the current standard for React: pointer + keyboard + touch sensors out of the box, which the spec's mobile-reorder (FR-042) and accessibility posture require. Native HTML5 drag is unreliable on touch and needs manual a11y; up/down buttons contradict the spec's explicit drag-handle wording.

**Alternatives considered**: native HTML5 DnD (rejected — touch/a11y), `react-beautiful-dnd` (unmaintained), move buttons (rejected — deviates from spec).

## Decision 6 — Map reuse: neutral day + client-driven selection highlight

**Decision**: Reuse `TourMap` + `RouteLayer` unchanged. Add:
- `DayLayer` (adapted `WorkdayLayer`): draws every day leg — tour legs solid, connection legs dotted — in `--route-neutral`. When a tour is selected, its tour-leg and the two connection legs bracketing it render in `--primary` at full opacity; everything else drops to 50% (same visual grammar as 015, but keyed by selected tour index, computed client-side).
- `DayMarkers` (adapted `WorkdayMarkers`): warehouse marker + one "T{n}" marker at each tour's entry point.
- Selected tour's numbered stops via `TourMap`'s existing numbered-marker rendering (pass the selected tour's stops as the map's `stops`), and its road path via `RouteLayer`.

**Rationale**: The spec says the map is "identical… except no projected tour." The candidate-highlight machinery maps cleanly onto "selected tour" once the server stops deciding the highlight. Adapting into new files (vs. flagging the shared ones) protects the tour page from regression (Constitution I / Reuse Safety table).

**Lazy road geometry**: `use-day-geometry` copies `use-workday-preview`'s pattern verbatim — legs render on straight fallback immediately, each un-traced leg is fetched via the frozen `POST /api/tour/geometry` (no `tour_id`, so no persistence side effect), keyed per day-load so stale traces only warm the cache (FR-039). This is the "fallback until data arrives" behaviour the user asked to mirror.

## Decision 7 — Edit round-trip carries a return target

**Decision**: The row Edit visits `tour/{tour}/edit?return_to_driver={id}&return_to_date={date}`. `TourPageController::edit` threads an optional `returnTo` into the `editTour` prop. On a successful re-optimize the optimize page performs an Inertia visit back to `/driver/{id}?date=…`; back/cancel visits the same without optimizing; failure stays put.

**Rationale**: FR-027/a/b. Additive only — when `returnTo` is absent the optimize page behaves exactly as today (regression-free). Reuses the existing edit-in-place tour flow (020) untouched otherwise.

**Alternatives considered**: browser history back — rejected: unreliable after a re-optimize replaces page state, and can't distinguish confirmed vs abandoned.

## Decision 8 — Break/driven/stop/total reuse the 019 definitions

**Decision**: `DayWorkdayService` builds the day's `TourSegment[]` from the stored assignments (no candidate) and calls `WorkdayEstimator::total` + `MandatoryBreak::secondsFor` exactly as `DriverAvailabilityService` does, minus the candidate segment and the counterfactual. Total = driven + stop + break; any null connection → `incomplete` → total shown as a lower bound with the warning marker (FR-013), reusing the existing `projected_incomplete` presentation.

**Rationale**: The spec's assumption fixes these to the existing projected-workday definitions. Direct reuse guarantees the driver page and the assignment preview agree.

## Testing strategy

- **Back-end (PHPUnit)**: `DayWorkdayService` totals incl. unknown-duration lower bound; `DayLegsBuilder` leg order/kinds; `TourOrderService` recompute picks nearest start + force fallback + conflict; `UpdateDriverRequest` validation; `DriverTourRepository::reorder` transaction + sequence. Feature tests for each endpoint incl. 404 foreign/unknown driver, 409 conflict, 422 unroutable, 200 force.
- **Front-end (Vitest/jsdom)**: `use-driver-day` cancellation/stale-discard; dirty-check enabling Update; warehouse-change advisory; `TourList` select/unfold/hover classes; drag reorder enabling Tour-order Update and relabeling T-markers (assert on data, not MapLibre paint — jsdom limitation, per `workday-layer.test.tsx`); force-save appears on 422.
- **No changes** to existing optimize/geometry/drivers/assign tests beyond, if needed, subject-retarget — the frozen contracts hold.
