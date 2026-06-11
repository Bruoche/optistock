# Research: Per-Stop Delivery Duration & Tour Duration Total

## R1 — Where the stop total is computed

**Decision**: Compute the stop total **entirely on the frontend**: `waitTimeS = Σ(durationMinutes) × 60`,
derived in `useTourOptimization` from its `stops`. Durations **never leave the browser** — the optimize
request, its response, the queue job, the broadcast, the status endpoint, and `TourCache` are all unchanged.

**Rationale**: The stop total is a trivial, order-independent sum of values the client already owns. The
earlier design sent a `durations` array to `POST /api/tour/optimize` so the controller could return
`wait_time_s = sum × 60` as a response sibling. But the backend made **no other use** of those durations: they
were not sent to the OpenStreet TSP API and were deliberately kept out of the optimize cache key — the server
received them only to echo their sum straight back. That round-trip bought nothing and cost a request field, a
response field, server-side validation, a documented cache caveat, and `OptimizeState` plumbing to carry the
echoed value through submit→pending→done. Computing the sum where the data already lives removes all of it.
The expensive, cached, deduplicated TSP result stays keyed by `(mode, loop, coordinatesHash)` and is now
**structurally** unreachable by a duration edit — not by a careful caching decision, but because durations
never reach the backend at all.

**Alternatives considered**:
- *Backend computes `wait_time` from a `durations` request field* (the previous design): adds request/response
  fields, validation, a cache caveat, and state-carry for a value the client can compute itself. Rejected as
  unnecessary complexity — the trigger for this rework.
- *Put `wait_time` inside the cached tour / TSP cache key*: would re-fire a multi-minute upstream call on a
  pure duration edit. Rejected long ago; now moot (durations never reach the cache).

## R2 — Delivering the stop total to the result view

**Decision**: Derive `waitTimeS` live from `stops` and pass it from the page into `ResultSummary`. **No**
`OptimizeState` field, **no** ref snapshot.

**Rationale**: `mode` and `loop` are snapshotted into `OptimizeState` (state + ref) because they are call-time
arguments to `optimize()` that the persistent UI does not otherwise hold once a result settles asynchronously.
Stop durations are different: they are persistent `stops` state owned by the hook, and they are **frozen**
between submit and `done` — the stop list is locked (non-interactive) while optimizing, and on `done`
`ResultSummary` replaces `StopList`, so no edit is possible in between. Therefore `Σ durationMinutes` at render
time always equals the durations the tour was optimized with; deriving it live is correct and strictly simpler
than carrying a snapshot through the state machine.

**Alternatives considered**:
- *Carry `waitTimeS` in `OptimizeState` like `mode`/`loop`*: defensible by analogy, but adds a field to three
  state variants and a ref for a value that is already derivable from frozen state. Rejected as redundant.

## R3 — Where durations live and their default

**Decision**: Add `durationMinutes` to the client `Stop` view model, defaulted to a frontend constant
`DEFAULT_STOP_DURATION_MINUTES = 10` assigned in `addStop`. A `MAX_STOP_DURATION_MINUTES = 1440` constant
bounds input.

**Rationale**: Durations are transient UI state on the stop the planner is editing; the `Stop` view model is
their natural home. The default and the ceiling are frontend constants because the backend has no use for
either — keeping them as a server config value (as an earlier commit did) would split ownership of a purely
client concern across the wire for no benefit. `1440` (24 h/stop) blocks absurd/overflow input without
constraining realistic deliveries.

**Alternatives considered**:
- *Default sourced from server config, passed as an Inertia prop*: keeps a single server-authoritative default,
  but reintroduces a backend dependency for a frontend-only feature. Rejected — front owns it end to end.

## R4 — Unit and "delivery unavailable = 0"

**Decision**: `waitTimeS` is in **seconds** (minutes × 60) so it adds directly to the existing second-based
`total_duration_s` / road `duration_s`. The displayed **Tour duration** = `(deliveryS ?? 0) + waitTimeS`; a
null/unavailable delivery time contributes **0** (never makes the total unavailable). The existing **Time on
road** keeps its current `null → "Unavailable"` rendering.

**Rationale**: Working in one unit (seconds) end-to-end means `ResultSummary` reuses its local
`formatDuration(seconds)` for both figures — no new formatting path. Coercing a null delivery to 0 reproduces
the worked example (2-point, 15 + 10 min ⇒ 25 min before legs; ⇒ 45 min once the 20-min trace arrives) and
satisfies FR-011.

## R5 — Where durations are edited in the UX

**Decision**: Durations are edited in the pre-optimize `StopList` (a numeric field per row, default 10). The
done-state view (`ResultSummary`) shows the two totals and recalculates **Tour duration** when road metrics
arrive; changing durations again means starting a new tour (reset), consistent with how `mode`/`loop` are
fixed once a tour is `done`.

**Rationale**: `ResultSummary` already replaces `StopList` on `done`, and `mode`/`loop` are likewise immutable
post-optimization — you reset to change them. Treating durations the same keeps one consistent interaction
model and is what makes the live-derive in R2 safe. The only live post-`done` recalculation is travel time
(road metrics overriding the estimate), which FR-008 is satisfied by.
