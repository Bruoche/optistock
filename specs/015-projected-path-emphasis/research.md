# Research — Projected Path Emphasis (015)

Builds on 014 (workday legs + `WorkdayLayer`) and 002 (`RouteLayer` runtime CSS-var resolution).
This is a presentation-layer refinement; the research is design choices, not unknowns.

## Existing slice recap

- `WorkdayLegsBuilder::build` assembles legs in chain order and ends with exactly two connection
  calls: `connection(lastPriorEnd → candidateStart)` and `connection(candidateEnd → warehouse)`.
  With no prior tours those two are the *only* legs.
- `WorkdayLeg` is an immutable value object with a `toArray` used by the drivers payload; the
  front's `WorkdayLeg` type mirrors it 1:1 (single-word keys, no snake/camel mapping).
- `WorkdayLayer` already resolves `--route-neutral` at runtime via getComputedStyle and paints
  every leg neutral; `RouteLayer` resolves `--primary` the same way for the candidate tour.
- `use-tour-drivers` copies `legs` from the payload verbatim onto `Driver.legs`.

## Decisions

### R1 — Server marks the bracketing connections with a `highlight` flag

**Decision**: Add `highlight: bool` to `WorkdayLeg`; `WorkdayLegsBuilder` sets it `true` on the
two candidate-adjacent connection calls, `false` on all others.

**Rationale**: The builder is the single place that already knows which connections attach the
candidate — they are the last two connection calls, and this holds identically when there are no
prior tours. Emitting a boolean keeps the front a pure renderer, matching the existing
`dotted`/`loop` render-hint pattern.

**Alternatives considered**: Front infers the two legs by position (e.g. the leg before/after the
candidate slot). Rejected — it re-encodes chain-shape assumptions the builder owns, needs a
special case for zero prior tours, and duplicates ordering knowledge; a server flag is one source
of truth.

### R2 — The flag drives both color and opacity

**Decision**: `WorkdayLayer` paints a highlighted leg in the primary role color at
`line-opacity: 1`, a non-highlighted leg in the neutral role color at `line-opacity: 0.5`.

**Rationale**: One boolean expresses both emphasis dimensions the spec asks for (FR-001..006).
The candidate tour is drawn by `RouteLayer`, already primary and opaque, so no `RouteLayer` paint
change is needed — the emphasis set (candidate + its two connections) is uniformly primary/opaque
by construction. Dash style stays keyed to `dotted`, independent of `highlight` (FR-003).

**Alternatives considered**: A separate opacity field or a per-leg color string in the payload —
rejected as leaking styling into the contract and violating the palette-role rule (Constitution
VI). A boolean + palette lookup keeps colors role-named and single-sourced.

### R3 — Resolve `--primary` locally in `WorkdayLayer`; leave `RouteLayer` untouched

**Decision**: `WorkdayLayer` adds its own `primaryColor()` beside its existing `neutralColor()`,
each reading a `--…` role variable at runtime via getComputedStyle — the exact pattern already
present in both `RouteLayer` and `WorkdayLayer`. `RouteLayer` is not modified.

**Rationale**: The candidate-tour rendering (`RouteLayer`) works and has no component test; touching
it to extract a shared resolver adds regression risk for zero functional gain. Constitution VI's
actual requirement — colors changeable from one place — is already met: every value lives once in
`app.css` as a palette variable, and all resolvers read those variables (no hex at point of use).
The runtime-resolver helper is a tiny idiom, not the single source the principle guards.

**Alternatives considered**: Extract a shared `route-colors` helper used by both layers. Rejected on
explicit direction — it edits the working `RouteLayer` system and widens the blast radius; the
palette single-source is unaffected either way. Duplicating the ~5-line resolver idiom is the
smaller, safer cost.

### R4 — Contract evolution only

**Decision**: `GET /api/tour/drivers` legs gain `highlight`; nothing else changes (v3 → v4).

**Rationale**: Purely additive. `use-tour-drivers` already spreads `legs` through, so only the
`WorkdayLeg` type widens; no hook or mapping change. No request, validation, endpoint, or schema
change.

**Alternatives considered**: A separate styling endpoint or a derived client-side computation —
both heavier than one additive boolean on an existing array.
