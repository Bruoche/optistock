# Implementation Plan: Driver Page Map & Day-Bar Fixes

**Branch**: `025-driver-management-page` (refinement — no new branch) | **Date**: 2026-07-23 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/026-driver-map-bar-fixes/spec.md`

## Summary

Frontend-only presentation fixes on the existing driver-management page (feature 025). Three defects: (1) tour lines don't appear until a tour is clicked; (2) selecting a tour leaves a straight line drawn over its polyline; (3) day-bar placement + weekday-label alignment. Root causes are all in the map-layer anchoring and the redundant selected-tour route line — both resolved together — plus an opacity constant and a small layout/label refactor of the day bar. No backend, no endpoint, no data-model change; only three files touched, and nothing outside the requested fixes.

## Technical Context

**Language/Version**: TypeScript 5 / React 19 (Inertia 3, `react-map-gl` 8 / MapLibre GL), Tailwind v4.

**Primary Dependencies**: Existing only. No new dependency.

**Storage**: N/A (presentation).

**Testing**: Vitest + Testing Library (jsdom does not evaluate MapLibre paint — layer opacity/color is asserted via the mocked `Layer` paint props, as the existing `day-layer.test.tsx` already does).

**Target Platform**: Web (desktop + mobile 320–2560 px).

**Project Type**: Web app (Inertia/React frontend).

**Performance Goals**: Lines visible < 1 s on load (SC-001); traced polylines replace straight lines as they arrive (unchanged lazy-trace timing).

**Constraints**: Change ONLY the requested behaviour. Colours stay role-named (Constitution VI). Endpoints/payloads frozen (FR-016). Dotted/solid + neutral/primary roles preserved (FR-010).

**Scale/Scope**: 3 files edited, 1 test updated. Bounded refinement.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Quality First** — PASS. The one behaviour change with a test (`DayLayer` no-selection opacity) has its assertion updated (0.75); no new logic paths beyond the three fixes.
- **II. Readable by Default** — PASS. Removing the redundant `RouteLayer` line and the fragile `beforeId` anchor *simplifies* the map composition; the day-bar refactor uses the existing `Figure` label/value pattern.
- **III. Simple & Transparent** — PASS. Fewer moving parts on the map (one line per segment, no cross-layer `beforeId` coupling).
- **IV. Robustness as Standard** — PASS. On-load rendering no longer depends on a conditionally-mounted layer; failed traces still keep the straight fallback (unchanged).
- **V. Performance with Clarity** — PASS. No extra work; the lazy-trace pipeline is untouched (it already traces all legs).
- **VI. Consistent, Reusable Front-End Styling** — PASS. Opacity via paint/Tailwind; colours remain `--route-neutral` / `--primary`; the day bar keeps role tokens. No off-palette values.

**Post-Phase-1 re-check**: PASS — no new violations; scope is narrower after the fix (a layer removed).

## The three fixes (what changes, and only this)

### Fix A — tours draw on load + single line per segment (FR-001–006)

`resources/js/components/driver/day-layer.tsx` and `resources/js/pages/driver/manage.tsx`.

- **Remove the `beforeId={TOUR_ROUTE_LAYER_ID}` anchor** from `DayLayer`'s `Layer`. It exists to sit day legs *under* the selected-tour route line, but that target layer only mounts when a tour is selected (via `RouteLayer`), so on load MapLibre has no anchor and the legs never render. Without the anchor, line layers append above the raster tiles (correct) and render immediately from the day's data.
- **Remove the selected-tour `RouteLayer`** from `manage.tsx`. It draws a straight stop-path line that overlaps `DayLayer`'s highlighted (traced) tour leg — the "two lines at once" defect. `DayLayer` already draws the selected tour's line (highlighted, primary) and traces it straight→polyline via `useDayGeometry`. Dropping `RouteLayer` yields exactly one line per segment. The `TOUR_ROUTE_LAYER_ID` import goes too.
- **Keep** the selected tour's numbered stop markers — they come from `TourMap stops={selectedStops}`, independent of `RouteLayer`.
- **Z-order safeguard**: `beforeId` also kept neutral legs *under* the route line; with it gone, legs stack in array order and a dimmed neutral leg drawn later (e.g. another tour's connection meeting the warehouse) could paint over the highlighted (1.0) selected tour. So `DayLayer` MUST draw non-highlighted legs first and highlighted legs last, keeping the selected tour + its bracketing connections on top. Each leg keeps its stable `key`/`id` (`day-{kind}-{index}`) so only paint diffs, never a remount, when the selection changes.
- Net: legs render on load as straight fallbacks (each leg's `geometry ?? path`), and `useDayGeometry` swaps each straight line for its polyline in place — the already-built lazy-trace behaviour, now visible without a click.

### Fix B — opacity (FR-007–009)

`day-layer.tsx` paint only.

- `line-opacity`: `isHighlighted ? 1 : (hasSelection ? 0.5 : 0.75)`.
  - No selection → **0.75** neutral (was 1).
  - Selection → highlighted (selected tour + its two bracketing connections) **1.0**; every other segment **0.5** (unchanged "dimmed further").
- Colour, width, dash unchanged (FR-010).

### Fix C — day-bar placement + weekday label alignment (FR-011–015)

`manage.tsx` (move `<DayBar>`) + `day-bar.tsx` (label/alignment).

- **Placement**: move `<DayBar>` from above the map to between the map region and the tour-list region in `manage.tsx` (order becomes identity → map → day bar → tour list).
- **Weekday as a title label**: in `day-bar.tsx`, restructure the day-navigation group so the weekday name is a label *above the date field only*, on the bar's label row, with `[prev] [date] [next]` on the value row. Reuse the existing label styling so it matches the other figures' labels.
  - Group: `<div class="flex items-end gap-2">` → `[prev ActionButton]`, `<div class="flex flex-col items-center">[weekday label][TourDateInput]</div>`, `[next ActionButton]`. `items-end` bottom-aligns the arrows to the date input (the value row); the weekday label floats above the date column only.
- **Bar-wide alignment**: align the bar's flex items so all labels share the top row and all values the bottom row (each item is the existing `Figure` label/value shape; give the day-nav group the same shape). The "Tour order" group already is a label+control column and stays.
- Wrapping on narrow screens is preserved (`flex-wrap` retained); no horizontal overflow (FR-015).

## Files touched (exhaustive)

```text
resources/js/components/driver/day-layer.tsx      # remove beforeId; opacity 0.75/1/0.5; drop TOUR_ROUTE_LAYER_ID import
resources/js/pages/driver/manage.tsx              # move <DayBar> below map; remove selected-tour <RouteLayer> + its import
resources/js/components/driver/day-bar.tsx        # weekday title-label above date; label/value row alignment
resources/js/components/driver/day-layer.test.tsx # update no-selection opacity assertion 1 → 0.75
```

Nothing else changes. `RouteLayer`, `TourMap`, `DayMarkers`, `TourList`, the hooks, and all backend stay untouched (FR-016).

## Project Structure

### Documentation (this feature)

```text
specs/026-driver-map-bar-fixes/
├── plan.md              # This file
├── research.md          # Root-cause analysis + decisions
├── contracts/
│   └── ui-map-bar.md    # The visual/behavioural contract for the map + bar
├── quickstart.md        # How to verify
└── checklists/requirements.md
```

No `data-model.md` — this feature introduces no data or entities (presentation-only). Recorded here deliberately rather than left as an empty placeholder.

### Source Code

See "Files touched" above — three components + one test under `resources/js/`.

**Structure Decision**: Existing Inertia/React layout; edits are confined to the three driver-page presentation files created in feature 025.

## Complexity Tracking

No constitution violations. No new dependencies, no new abstractions — the change is a net simplification (one map layer and one cross-layer coupling removed).
