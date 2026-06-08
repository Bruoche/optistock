# Research: Delivery Mode Selection

## R1 — Allowed mode set & live-API support

**Decision**: Support exactly `trucking` (default), `driving`, `walking`. No new live-API verification
is required.

**Rationale**: Feature 002 already wired `mode` to the live OpenStreet `/route` endpoint and settled the
accepted set in `TourGeometryRequest` (`in:driving,walking,trucking`). The same `mode` query parameter
is sent to the TSP endpoint (`OpenStreetTspClient` already passes `mode` from config). The three values
are therefore already exercised against the live API; 003 only routes them from a UI control instead of
a config constant.

**Alternatives considered**: Adding more modes (cycling, etc.) — out of scope; the spec names exactly
three. A live re-probe — unnecessary, the contract is unchanged from 002.

## R2 — How mode must participate in the optimization cache

**Decision**: Make `mode` an explicit segment of the tour and active-job cache keys
(`tour:{mode}:{hash}`, `tour:active:{userId}:{mode}:{hash}`); the status key stays keyed on `jobUuid`.

**Rationale**: The optimized stop order and metrics depend on the mode, so two requests with identical
coordinates but different modes are **different** tours. The current key (`tour:{hash}`) would serve a
`trucking` tour to a `walking` request — a silent correctness bug. Threading `mode` through the key
builders keeps `coordinatesHash` meaning exactly "a function of the coordinates" and makes the mode
dimension visible at every call site (naming-philosophy: self-evident names).

**Alternatives considered**: Folding `mode` into the sha256 input (`hash([mode, coords])`). Rejected:
the variable is named `coordinatesHash`; silently mixing mode in makes the name lie and hides the
dimension from logs and key inspection. The explicit-segment approach is what the user asked for ("the
job cache should contain and expect this field").

## R3 — Centralizing the allowed set

**Decision**: Introduce `App\Enums\DeliveryMode` (string-backed) as the single source of the allowed
modes and the default; both form requests validate via `Rule::enum(DeliveryMode::class)`. The front
mirrors it with a TS union + an ordered options array.

**Rationale**: Without it, the `in:driving,walking,trucking` literal is duplicated across
`OptimizeTourRequest` and `TourGeometryRequest`, and the default `trucking` is repeated in config and
controllers — constitution III forbids duplicated logic. An enum gives one authoritative list, typed
call sites, and a natural `default()` helper.

**Alternatives considered**: Plain repeated `in:` strings — duplication, drift risk. A config array —
weaker typing than an enum, no exhaustiveness at call sites.

## R4 — Keeping optimization mode and polyline mode congruent (FR-007)

**Decision**: Snapshot the mode used for a tour into the front-end `done` state; the geometry hook sends
that snapshot, not the live dropdown value.

**Rationale**: The planner may change the dropdown after a result is shown (FR-008 says that must not
alter the displayed tour). If geometry read the live dropdown, a shown trucking tour could be re-traced
for walking — a mismatch (FR-007). Binding the mode to the result object eliminates the race by
construction.

**Alternatives considered**: Re-tracing on every dropdown change — contradicts FR-008 and wastes calls.
Trusting the live value — reintroduces the mismatch.
