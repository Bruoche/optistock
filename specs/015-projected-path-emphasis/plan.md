# Implementation Plan: Projected Path Emphasis

**Branch**: `015-projected-path-emphasis` | **Date**: 2026-07-03 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/015-projected-path-emphasis/spec.md`, building directly
on feature 014 (`WorkdayLeg`/`WorkdayLegsBuilder`, `WorkdayLayer`, the legs-bearing
`GET /api/tour/drivers` payload) and 002 (`RouteLayer` runtime CSS-var color resolution).

## Summary

Today every leg of a driver's projected workday is drawn in the neutral role color; only the
candidate tour (`RouteLayer`) is primary orange. This feature raises the two connection drives
that **bracket the candidate tour** — the drive into its start and the drive out of its end — to
that same primary orange, and dims every other leg to 50% opacity, so the projected path (tour +
its connecting drives) reads as one continuous orange commitment and the already-planned day
recedes behind it.

Mechanically, one boolean travels end to end:

1. **Backend marks the bracketing legs.** `WorkdayLeg` gains a `highlight` flag; the two
   candidate-adjacent connections built by `WorkdayLegsBuilder` set it `true`, every other leg
   `false`. The knowledge of *which* connections attach the candidate already lives in the
   builder (they are the last two connection calls) — the flag just exposes it. It ships on each
   leg in the `GET /api/tour/drivers` payload (contract v3 → v4).
2. **Frontend renders from the flag.** `WorkdayLayer` reads `highlight`: a highlighted leg draws
   in the primary role color at full opacity, a non-highlighted leg in the neutral role color at
   `0.5` opacity — one flag, two paint properties. This mirrors the existing `dotted`/`loop`
   render-hint pattern; the front infers nothing about chain shape.
3. **Primary color resolved locally.** `WorkdayLayer` gains its own `primaryColor()` resolver of
   the `--primary` role, mirroring its existing `neutralColor()` (and `RouteLayer.primaryColor`) —
   same runtime CSS-var pattern already used across the tour layers. `RouteLayer` is **not**
   touched; the working candidate-tour rendering system is left exactly as is.

No backend chain/estimator/assignment change, no migrations, no new endpoints. The candidate
tour's own `RouteLayer` rendering (primary, full opacity) is unchanged.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel 12; TypeScript / React 19 + Inertia.

**Primary Dependencies**: MapLibre GL via react-map-gl; Tailwind v4 role-named palette
(`--primary`, `--route-neutral`); shadcn/ui.

**Storage**: MySQL/SQLite — untouched (no schema change).

**Testing**: PHPUnit (`Tests\TestCase`); Vitest + Testing Library.

**Project Type**: web app (Laravel + React SPA).

**Performance Goals**: no change to the drivers endpoint — the flag is a boolean computed inline
while legs are already being assembled; zero added queries or routing calls. Preview render stays
same-frame (SC-001, 014).

**Constraints**: colors referenced only through role-named palette variables (Constitution VI);
emphasis is a static property of a leg's role, independent of its geometry state (FR-007); no
regression to 014's chain, assignment flow, or rapid-cycling correctness (SC-004).

**Scale/Scope**: a handful of drivers, a few legs each; one new boolean field, two changed files
per side plus one extracted helper.

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — every changed surface covered: `WorkdayLegsBuilder` (Unit —
  the two candidate-bracketing connections carry `highlight: true`, all prior-tour connections and
  tour legs `false`, no-prior-tours case both connections `true`); drivers endpoint (Feature —
  `highlight` present per leg with the correct positions); `WorkdayLeg::toArray` (`highlight`
  serialized); frontend `workday-layer` (highlighted leg → primary color + `line-opacity` 1;
  non-highlighted → neutral + `line-opacity` 0.5; dash unchanged by highlight; `--primary`
  resolved by the layer's own `primaryColor()`, covered by the same `workday-layer` test that
  stubs `--primary`). PASS.
- **II/III. Readable & Simple** — one boolean added to an existing value object and its factory;
  the front branches on it for two paint props; no new abstraction — `WorkdayLayer` gains a local
  `primaryColor()` twinning its `neutralColor()`, the same idiom already in the file. The builder
  gains a defaulted parameter, no new method. PASS.
- **IV. Robustness** — the flag is derived structurally (the two candidate connection calls), so
  it is correct for the no-prior-tours case by construction; a failed/absent geometry leg keeps
  its straight line **and** its emphasis (color/opacity keyed to role, not geometry — FR-007);
  no new failure path. PASS.
- **V. Performance with Clarity** — a boolean set inline during existing leg assembly; no added
  queries, routing calls, or payload of consequence (one bool per leg). Front does constant-time
  per-leg branching. PASS.
- **VI. Consistent, Reusable Styling** — both colors are existing palette roles (`--primary`,
  `--route-neutral`), resolved at runtime through the same CSS-var pattern the tour layers already
  use (`WorkdayLayer` adds a local `primaryColor()` beside its `neutralColor()`, mirroring
  `RouteLayer`); the single source of the color values stays the palette variables in `app.css`, so
  a re-theme is still one edit. `0.5` is a MapLibre `line-opacity` paint value, not a color literal;
  no raw hex introduced at point of use. PASS.

No violations. (Complexity Tracking omitted.)

## Decisions

Full rationale + alternatives in [research.md](research.md); condensed:

- **D1 — A `highlight` flag on the leg, set server-side.** The server assembling the chain is the
  only place that already knows which two connections attach the candidate (they are the last two
  connection calls in `WorkdayLegsBuilder::build`). Expose that as a boolean; the front renders
  from it. Rejected: the front inferring the bracketing legs by index — fragile (encodes
  chain-shape assumptions, mishandles the no-prior-tours case, duplicates ordering the builder
  already owns). (R1)
- **D2 — One flag drives color *and* opacity.** Highlighted → primary role + `line-opacity` 1;
  non-highlighted → neutral role + `line-opacity` 0.5. FR-001..006 all fall out of this one
  branch; the candidate tour's own layer is already primary + opaque, so no `RouteLayer` paint
  change. (R2)
- **D3 — Resolve `--primary` locally in `WorkdayLayer`; don't touch `RouteLayer`.** `WorkdayLayer`
  adds its own `primaryColor()` beside its existing `neutralColor()`, both reading a `--…` role var
  at runtime — the exact pattern already in `RouteLayer` and `WorkdayLayer`. The candidate-tour
  rendering system (`RouteLayer`) is deliberately left untouched to avoid any regression risk to a
  working, unit-untested component. Rejected: extracting a shared resolver — cleaner on paper but it
  edits `RouteLayer` for no functional gain and widens the blast radius; the palette values already
  live single-source in `app.css`, which is what Constitution VI actually requires. (R3)
- **D4 — Contract evolution only.** `GET /api/tour/drivers` legs gain `highlight`; request,
  validation, and every other field unchanged (v3 → v4). `use-tour-drivers` already copies `legs`
  wholesale, so the field flows through with only the type widened. (R4)

## Project Structure (feature-specific)

Backend — **change**:
- `app/Services/WorkdayLeg.php` — add readonly `bool $highlight`; `connection()` factory gains
  `bool $highlight = false`; `tour()` passes `false`; `toArray()` emits `highlight`.
- `app/Services/WorkdayLegsBuilder.php` — private `connection()` gains `bool $highlight = false`;
  the two candidate-bracketing connection calls (`… → candidateStart`, `candidateEnd → …`) pass
  `true`; prior-tour connections stay `false`.

Frontend — **change**:
- `resources/js/components/tour/workday-layer.tsx` — add a local `primaryColor()` resolver of
  `--primary` (mirror of the existing `neutralColor()`, fallback `#ff9a3c`); per leg pick
  `highlight ? primaryColor() : neutralColor()` for `line-color` and `highlight ? 1 : 0.5` for a
  new `line-opacity` paint property.
- `resources/js/types/tour.ts` — `WorkdayLeg` gains `highlight: boolean`.

`resources/js/components/tour/route-layer.tsx` — **no change** (its `primaryColor` stays local; the
candidate-tour rendering system is untouched).

`resources/js/hooks/use-tour-drivers.ts` — **no change** (already copies `legs` verbatim; the
widened `WorkdayLeg` type carries `highlight` through).

Tests: `tests/Unit/WorkdayLegsBuilderTest.php` (extend — highlight positions, no-prior case),
`tests/Feature/DriverAvailabilityTest.php` (extend — `highlight` in the legs payload),
`resources/js/components/tour/workday-layer.test.tsx` (extend — primary+opaque highlighted leg,
neutral+0.5 non-highlighted, dash unaffected). Because `highlight` is a **required** type field,
the existing `WorkdayLeg` literals in `workday-layer.test.tsx` and `use-workday-preview.test.ts`
(their `leg()` factories + two inline literals in the latter) get `highlight: false` so the 014
suites still compile and pass. Any existing `WorkdayLeg::toArray` assertion extended to include
`highlight`.

Out of scope (unchanged from 014): warehouse marker; multi-driver comparison; configurable dim
level (50% is fixed per the spec); refactoring the shared CSS-var resolver (`RouteLayer` stays as
is — explicit decision, D3).

## Flow (select → emphasized preview)

1. Presentation: `GET /api/tour/drivers` responds with the 014 legs, each now carrying
   `highlight` — `true` on the two connections bracketing the candidate slot, `false` elsewhere.
2. Manager clicks a driver → `WorkdayLayer` draws every leg: the two highlighted connections in
   primary orange at full opacity flanking the still-primary candidate tour, every prior tour and
   pre-existing connection in neutral at 50% opacity.
3. Straight-line placeholders upgrade to road geometry in place (014); color and opacity are keyed
   to `highlight`, so they never change across the upgrade (FR-007).
4. A driver with no prior tours: both connection legs are candidate-adjacent → both highlighted
   (FR-004); nothing to dim.

## API contracts (this run)

- `GET /api/tour/drivers?mode&date&tour` — response legs gain `highlight` (boolean). Request,
  validation, and all other fields unchanged. See `contracts/driver-workday.md` (v4).

## Design Artifacts (this run)

- `research.md` — decisions R1–R4 with alternatives.
- `data-model.md` — no DB changes; `WorkdayLeg.highlight`, builder wiring, frontend type/render.
- `contracts/driver-workday.md` — legs payload with `highlight` (v4).
- `quickstart.md` — emphasis walkthrough + verification checks.

---

Generated by speckit.plan on 2026-07-03
