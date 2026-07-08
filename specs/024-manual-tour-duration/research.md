# Phase 0 Research: Manual Tour Duration Fallback

Decisions resolving the spec + user plan input against the current (post-023) codebase. No open `NEEDS CLARIFICATION`.

## D1 — New endpoint shape: mirror the optimize `done` payload

**Decision**: `POST /api/tour/force`, `auth` + `throttle:tour-read`, name `tour.force`. Request = the optimize body plus a required drive duration. Success returns the **same shape** as an optimize cache-hit:
`200 {status:'done', data:{id, ordered_stops:[{lat,lng,order}], total_distance_m:null, total_duration_s:<manual drive seconds>}}`.
Persist failure → `200 {status:'failed', error:{code:'persist_failed', message}}`. Validation → `422`; foreign/missing `tour_id` → `404`; assigned `tour_id` → `422`.

**Rationale**: The frontend already has a `done` path (`settleDone` → result view → driver assignment). Reusing the shape means the forced tour flows through assignment with zero new frontend plumbing. `total_duration_s` in the optimize payload is the driving-only total (from `STEPS_DURATIONS.TOTAL`, saved as `travel_duration_s`) — the manual value is exactly that quantity, so the mirror is semantically honest.

**Alternatives rejected**: A bespoke response shape (would force new frontend settle logic); `202` async (pointless — no slow call to defer).

## D2 — Throttle: `tour-read`, not `tour-optimize`

**Decision**: Use the lightweight `throttle:tour-read` limiter (as `geometry`/`drivers`/`assign` do), not `tour-optimize`.

**Rationale**: `tour-optimize` (10/min) guards the *expensive upstream* call. Force does no upstream call; it is a trivial write like assign. It must also stay usable precisely when optimize is rate-limited or failing.

## D3 — Reuse `TourRecorder` + `TourRepository` unchanged

**Decision**: `TourForcingService::force()` builds ordered stops in **input order** (`order = array index`) and a `durationByCoord` map, then calls the existing `TourRecorder::record(userId, mode, loop, orderedStops, durationByCoord, distanceM:null, durationS:manual, editTourId)`. Create vs overwrite-in-place is already handled inside `record()`/`TourRepository` by `editTourId`.

**Rationale**: `record()` + `TourRepository::createTourWithStops`/`overwriteTourWithStops` already persist a tour + stops in one transaction, already throw+rollback on a vanished edit target (→ `persist_failed`, FR-008), already store `travel_duration_s`/`total_distance_m`. Passing `distanceM:null, durationS:manual` needs **no repository change**. Coordinates are normalized with the same `CoordinateNormalizer` the optimize path uses, so a forced tour's stored precision matches an optimized one (keeps start-selection + geometry consistent).

**Alternatives rejected**: A new `recordForced()` / direct repository call — duplicates persistence already centralized by 023.

## D4 — Manual value = tour drive duration only (`travel_duration_s`)

**Decision**: The typed minutes × 60 are written to `travel_duration_s`. Per-stop `duration_s` values are saved from the request stops unchanged. `Tour::total_duration_s` accessor then yields `travel_duration_s + Σ stop duration_s` for the workday, exactly as for an optimized tour.

**Rationale**: Confirmed by the user. The per-stop durations never came from the API; only the drive total did. This is the single field a dead API leaves missing, so it is the only one the manual value fills. Because `travel_duration_s` is now non-null, the model's unknown-propagation (`null travel → null total`) naturally flips to a concrete total — the workday stops being incomplete on account of *this* tour.

## D5 — Duration validation bounds

**Decision**: `travel_duration_s` = `required|integer|min:1|max:86400` (24 h). Reject empty/zero/negative/non-integer/over-max with a clear message; no tour saved. Frontend field is **minutes**, whole numbers, mirrored-clamped like the stop-duration input (empty/NaN/negative blocked, floored), and sends seconds on submit.

**Rationale**: `min:1` enforces "a valid duration is required" (zero is not a drive). 24 h matches the per-stop `MAX_STOP_DURATION_MINUTES` ceiling already in the app and is a plausible single-day driving cap; beyond it is implausible input, not data. Client + server both validate (defence in depth).

**Alternatives**: No upper bound (lets an overflow/typo persist an absurd tour); minutes on the wire (breaks the app-wide `duration_s`-in-seconds convention — stops already send `duration_s`).

## D6 — Reveal only on failure; carry a `forced` flag through `done`

**Decision**: The drive-duration field + Force Tour button render **only when `state.status === 'failed'`** (the editing view already re-renders on failure with stops intact). The optimization hook gains `forceTour(mode, loop, durationMinutes)`; on `200 done` it settles the existing `done` state with a new `forced: true`. Optimize settles `forced: false`. `ResultSummary` reads `forced` to show a "Manually entered" duration badge.

**Rationale**: The `failed` state already keeps the `stops` array and shows `TourControlBar` — the natural, minimal place. A boolean on the done state is the smallest carrier of the transparency signal (FR-014) and needs no backend field (a forced tour is recognizable by `total_distance_m === null` too, but an explicit flag is unambiguous and survives a later distance backfill).

**Alternatives rejected**: A separate always-visible manual mode (contradicts FR-003); a new page/route (the result view already does everything needed).

## D7 — Driver-assignment back-end audit: already non-blocking, one gap

**Findings** (each external touch in `GET /api/tour/drivers` → `DriverAvailabilityService`):

| Call site | Failure behavior today | Blocking? |
|-----------|------------------------|-----------|
| `TravelTimeService::preload`/`durationBetween` (pooled `/route`) | Pool yields `ConnectionException` or failed `Response` → leg `duration_s: null`, **logged** `warning`; consumer counts null as 0 + flags incomplete | No (bounded by read timeout) — but **no connect timeout** → a dead host waits the full read timeout per batch |
| `WorkdayEstimator::total` | null durations → 0, `incomplete: true` (`projected_incomplete`) | No |
| `TourStartSelector::select` | all-null durations → falls back to first start candidate | No |
| `DriverAvailabilityService::rowsFor` | `Tour::with('stops')->findOrFail` — **DB only**, no API | No |
| `POST /api/tour/{tour}/assign` | `DriverTourRepository::assign` — **DB only**, unique-violation tolerated | No |

**Decision**: The path is structurally sound (unknowns degrade to flagged best-effort, never throw up to the request). The **one** hardening: `OpenStreetRouteClient` sets `->timeout()` (read) but no `->connectTimeout()`. Add `route_connect_timeout` (config, default 10 s), thread it via `AppServiceProvider`, and apply it on `traceLeg()` and on the pooled request in `TravelTimeService::fetchBatch` (via a `connectTimeout()` accessor on the client, mirroring `timeout()`), so a dead host fails fast instead of stalling each batch.

**Rationale**: Mirrors the TSP client, which already has `connect_timeout`. Pure fail-fast — no behavior change on the happy path, no call-count change (constitution V). Directly answers "at no point a blocking situation when the API is unavailable."

**Alternatives rejected**: Rewriting the workday estimator (already correct); making driver-availability async (unwarranted — it is fast when the API is up, and bounded when down).

## D8 — Geometry + distance of a forced tour: existing transparency suffices

**Decision**: No change to `useTourGeometry` / `TourGeometryService`. After a forced tour settles `done`, the frontend traces geometry as usual; with the API down every leg returns `ok:false` → straight segments + null totals → `ResultSummary` already shows road metrics as unavailable. `total_distance_m` is null on a forced tour → distance already renders unknown (FR-013).

**Rationale**: The per-leg fallback + null-total transparency built for 002/013 already covers a forced tour's missing route/distance. The only *new* transparency need is the manual-duration badge (D6).

## D9 — No schema change

**Decision**: Reuse `tours.travel_duration_s` (manual drive) + `tours.total_distance_m` (null) + `stops.position` (input order). No migration.

**Rationale**: A forced tour is a regular tour with a null distance and a hand-set drive duration; the columns already model exactly that (the model already treats null distance/travel as first-class).

## Testing strategy

- **Backend contract/feature**: force create (input order preserved, `travel_duration_s` set, distance null, response mirrors done); force edit-in-place with `tour_id`; vanished `tour_id` → `persist_failed`; validation matrix (missing/0/negative/non-int/over-max duration, bad coords, <2/>10 stops, foreign/assigned `tour_id`); forced tour then assignable + workday uses its duration. Retarget none; add new tests only.
- **Driver-path robustness**: `GET /api/tour/drivers` with `/route` faked down (HTTP fake: connection error) → `200` with rows, `projected_incomplete:true`, no exception; assert connect timeout is applied.
- **Frontend**: control bar shows field+button only on `failed`; `forceTour` posts + settles `done`; `ResultSummary` shows the manual badge when `forced`; empty/invalid duration disables/blocks force.
- **Gate**: `php artisan test`, `npm test`, eslint, `tsc`, prettier `format:check` (all green — see project memory: format:check is separate from lint).
