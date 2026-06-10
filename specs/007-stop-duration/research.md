# Research: Per-Stop Delivery Duration & Tour Duration Total

## R1 — Where `wait_time` is computed (and the caching trap)

**Decision**: Compute `wait_time` **in the optimize controller**, synchronously, from the request's
`durations` array (`wait_time_s = sum(durations_minutes) * 60`), and return it as a **sibling of the
response** (alongside `data` / `job_uuid`) — **never inside the cached tour body and never part of the
optimize cache key**. Durations are **not** sent to the OpenStreet API.

**Rationale**: The expensive, cached, deduplicated thing is the TSP optimization, keyed by
`(mode, loop, coordinatesHash)` (see `TourOptimizationService` + `TourCache`). `wait_time` is a trivial
order-independent sum that does not affect routing at all. If durations entered the cache key, editing a
single stop's minutes would re-fire a multi-minute upstream OpenStreet call for an identical route —
violating Performance-with-Clarity and the existing dedup design. Computing it in the controller from the
live request means a cache **hit** still returns a fresh, correct `wait_time` for the durations just sent,
with zero upstream cost. This honors the user's directive ("a new field in the response `wait_time`") while
keeping the heavy path untouched.

**Alternatives considered**:
- *Put `wait_time` inside the cached tour / TSP cache key*: pollutes the cache, re-triggers upstream calls on
  pure duration edits. Rejected (perf + dedup regression).
- *Compute `wait_time` purely on the frontend* (it already holds the stops): viable and even simpler, but the
  user explicitly wants the value returned by the backend (single authoritative figure + server-side duration
  validation). Followed the user's design; frontend still owns the final `delivery + wait` display sum.
- *Thread durations → job → broadcast → frontend*: the job has no use for durations (not sent upstream), and
  the async result path (broadcast/poll) would need a new field. Unnecessary — see R2.

## R2 — Delivering `wait_time` to the frontend in both sync and async paths

**Decision**: Return `wait_time_s` in the **immediate** optimize response (200 *and* 202). The frontend
**carries it through the `OptimizeState` machine** (submitting → pending → done) exactly the way `mode` and
`loop` are already carried — via state + a ref so an async result settled from a broadcast/poll still has it.
The `TourOptimized` broadcast, the status-poll endpoint, and `TourCache` are **left unchanged**.

**Rationale**: `mode` and `loop` set the precedent: request-time values that the result must stay congruent
with are snapshotted client-side, not round-tripped through the queue. `wait_time_s` is the same kind of
value (known at request time, independent of the async TSP compute). This keeps the change off the job /
cache / broadcast surface entirely — the minimal, lowest-risk footprint.

**Alternatives considered**:
- *Add `wait_time_s` to the broadcast + status payloads*: needs job-side plumbing for a value the job never
  uses; larger blast radius. Rejected.

## R3 — Request shape for durations

**Decision**: Add an optional `durations` array to `POST /api/tour/optimize`: one non-negative integer
(minutes) per coordinate, aligned by index. Validation: `durations` array; size equals `coordinates` size
when present; `durations.*` integer `min:0`, `max:1440`. When **absent**, the server defaults every stop to
**10** minutes (the feature default), so older/edge callers still get a sensible `wait_time`.

**Rationale**: A parallel `durations` array leaves the existing strict `coordinates` rules (the `[lat,lng]`
pair shape, ranges) completely untouched — lower risk than widening coordinates to `[lat,lng,duration]`
triples. Order-alignment does not matter for the **sum**, but one-per-stop alignment keeps the contract
self-describing and lets validation reject mismatched payloads. `max:1440` (24 h/stop) is a sane ceiling that
blocks absurd/overflow input without constraining realistic deliveries (the spec sets no hard cap, only
"format correctly").

**Alternatives considered**:
- *Coordinates as `[lat,lng,duration]` triples*: fewer arrays, but rewrites the well-tested coordinate
  validation and the `coordinates.map` on the client. Rejected for blast radius.
- *Require `durations` (not optional)*: marginally tighter, but the optional+default form is more robust and
  matches the "default 10" semantics natively. Chose optional-with-default.

## R4 — Unit and "delivery unavailable = 0"

**Decision**: `wait_time_s` is in **seconds** (minutes × 60) so it adds directly to the existing
second-based `total_duration_s` / road `duration_s`. The displayed **Tour duration** =
`(delivery_s ?? 0) + wait_time_s`; a null/unavailable delivery time contributes **0** (never makes the total
unavailable). The existing **Time on road** keeps its current `null → "Unavailable"` rendering.

**Rationale**: Working in one unit (seconds) end-to-end means the frontend reuses the existing
`formatDuration(seconds)` helper for both figures — no new formatting path. Coercing a null delivery to 0
exactly reproduces the user's worked example (2-point, 15 + 10 min ⇒ 25 min before legs; ⇒ 45 min once the
20-min trace arrives) and satisfies FR-011.

## R5 — Where durations are edited in the UX

**Decision**: Durations are edited in the pre-optimize `StopList` (a numeric field per row, default 10). The
done-state view (`ResultSummary`) shows the two totals and recalculates **Tour duration** when road metrics
arrive; changing durations again means starting a new tour (reset), consistent with how `mode`/`loop` are
fixed once a tour is `done`.

**Rationale**: `ResultSummary` already replaces `StopList` on `done`, and `mode`/`loop` are likewise immutable
post-optimization — you reset to change them. Treating durations the same keeps one consistent interaction
model. The only live post-`done` recalculation is travel time (road metrics overriding the estimate), which
FR-008 is satisfied by.
