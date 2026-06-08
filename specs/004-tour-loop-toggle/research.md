# Research: Tour Loop Toggle

## R1 — The TSP `tour` field values

**Decision**: Send `tour=closed` for a looped tour and `tour=open` for a one-way tour. Keep a boolean
`loop` (true = closed) at the HTTP/UI/cache boundary; map it to the `tour` string in the **job**
(`OptimizeTourJob`), which passes the string to the thin `OpenStreetTspClient`. A boolean is the natural
fit for the checkbox/toggle; the domain→API translation sits in the job (the layer that already owns
talking to the upstream API).

**Rationale**: Confirmed by the user from the OpenStreet documentation — the optimization endpoint takes
a `tour` field whose value is `closed` or `open`. The code already sends `tour=closed` hard-coded; this
feature makes it conditional. No live re-probe needed (the value set is documented).

**Alternatives considered**: Sending a boolean to the API — rejected, the API expects the string enum.
Inferring shape from another field — rejected, `tour` is the documented control.

## R2 — How loop participates in the optimization cache

**Decision**: Add a shape segment to the cache keys: `tour:{mode}:{shape}:{hash}` and
`tour:active:{userId}:{mode}:{shape}:{hash}`, where `{shape}` is `closed`|`open`.

**Rationale**: An open tour's optimal order and metrics differ from a closed tour's, so the two are
different results and must not collide — the same reasoning that made 003 key by `mode`. Threading an
explicit shape segment keeps `coordinatesHash` meaning coordinates only and makes the dimension visible
in keys and logs.

**Alternatives considered**: Folding shape into the sha256 input — rejected (hides the dimension,
muddies the `coordinatesHash` name; naming-philosophy: self-evident names). Reusing the `mode` segment —
rejected (orthogonal concerns).

## R3 — Default when `loop` is omitted

**Decision**: Default to `true` (closed). Omitted `loop` ⇒ the current closed-tour behaviour.

**Rationale**: Preserves backward behaviour for any caller/test that sends no `loop` (001/002/003), and
matches the spec default (FR-002, toggle on). No config knob is needed — unlike `mode`, looping has a
fixed, obvious default and no deployment-time override requirement.

**Alternatives considered**: A config flag like `services.openstreet.mode` — rejected as unnecessary
ceremony; the default is a domain constant, not an environment concern.

## R4 — Drawing an open route (no closing segment)

**Decision**: When open, trace only legs `0..count-2` (omit `(last → first)`), and set the front
`RouteLayer` `closed` flag to `false` so neither the road path nor the straight fallback appends a
return. Totals exclude the return leg by construction.

**Rationale**: `RouteLayer` already exposes a `closed` prop and `composeGeometry` already returns a
`closed` flag; both simply follow `loop`. `TourGeometryService` already iterates legs — gating the
closing leg on `$loop` is the minimal change. Satisfies FR-006/FR-009.

**Alternatives considered**: Always trace the closing leg and hide it on the front only — rejected: it
would wrongly include the return distance/duration in totals (violates FR-009) and waste an upstream
`/route` call.

## R5 — Congruence between optimization shape and drawn route (FR-007)

**Decision**: Snapshot `loop` into the front `done` state; the geometry hook sends that snapshot, not
the live toggle.

**Rationale**: Identical to the 003 mode-congruence decision. The toggle is hidden once a result shows
(editing-only), but binding the shape to the result object removes any chance of a closed tour being
re-drawn open (or vice versa).

**Alternatives considered**: Reading the live toggle — reintroduces the mismatch risk; rejected.
