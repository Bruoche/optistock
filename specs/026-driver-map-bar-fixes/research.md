# Research: Driver Page Map & Day-Bar Fixes

Root-cause analysis (from the feature-025 code) and the decisions behind the plan. All findings verified against the current `day-layer.tsx`, `manage.tsx`, and `day-bar.tsx`.

## Root cause 1 — no lines until a tour is clicked

**Cause**: `DayLayer` renders each leg's `Layer` with `beforeId={TOUR_ROUTE_LAYER_ID}` (`'tour-route-line'`). That layer is created only by `RouteLayer`, which `manage.tsx` mounts **only when a tour is selected**. On first load nothing is selected → the target layer does not exist → MapLibre cannot place the leg layers relative to a missing id, so they are not drawn. Selecting a tour mounts `RouteLayer`, the id appears, and the legs finally render — exactly the reported symptom.

**Decision**: Remove `beforeId` from `DayLayer`. The anchor was inherited from the 015/workday-layer pattern (keep neutral legs under the candidate's primary route). On the driver page there is no separate candidate route to sit under once the redundant `RouteLayer` is removed (see root cause 2), so the anchor has no purpose. Without it, line layers append above the raster basemap (correct) and draw as soon as the day data is present.

**Alternatives considered**: Make `beforeId` conditional (only when a selection exists) — rejected: still couples the layers and leaves the ordering fragile; removing the redundant route line makes the anchor unnecessary entirely.

**Z-order follow-on**: `beforeId` also kept neutral legs under the route line. Once removed, legs stack in draw order, so `DayLayer` must render non-highlighted legs first and highlighted legs last — otherwise a dimmed neutral leg drawn later (e.g. another tour's connection converging at the warehouse) could paint over the highlighted selected tour. Stable per-leg ids are retained so re-ordering on selection is a paint diff, not a remount.

## Root cause 2 — straight line stays on top of the polyline when selected

**Cause**: When a tour is selected, `manage.tsx` renders `<RouteLayer path={selectedTour.stops…}>` — a **straight** stop-to-stop line — while `DayLayer` simultaneously draws that same tour's leg, highlighted and traced to a road polyline by `useDayGeometry`. Two lines for one tour: the straight `RouteLayer` line over the `DayLayer` polyline.

**Decision**: Remove the selected-tour `RouteLayer` from `manage.tsx` (and its import + the now-unused `TOUR_ROUTE_LAYER_ID` import from `DayLayer`). `DayLayer` is already the single source of every segment's line, including the selected tour's (highlighted), and already replaces straight→polyline in place. This gives exactly one line per segment and fixes root cause 1 at the same time (no more `beforeId` target needed).

**Kept**: the selected tour's **numbered stop markers** come from `TourMap stops={selectedStops}` (DOM markers), not from `RouteLayer`, so they are unaffected — "numbered stops like the route presentation" still holds.

**Alternatives considered**: Feed the traced geometry into `RouteLayer` and suppress the `DayLayer` tour leg — rejected: more code, duplicates what `DayLayer` already does, and re-introduces the cross-layer coupling.

## Decision 3 — neutral opacity 75%

Current `line-opacity` = `!hasSelection || isHighlighted ? 1 : 0.5`. New = `isHighlighted ? 1 : (hasSelection ? 0.5 : 0.75)`:
- no selection → 0.75 (the requested "slightly transparent" neutral);
- selection → selected tour + bracketing connections at 1.0, everything else at 0.5 (unchanged).

Colour role (`--route-neutral` / `--primary`), width, and dash pattern are untouched (FR-010).

## Decision 4 — day-bar placement and weekday label

- **Placement**: `manage.tsx` moves `<DayBar>` to sit between the map region and the tour-list region (was above the map). Pure JSX reorder; the bar's props/behaviour are unchanged.
- **Weekday label + alignment** (`day-bar.tsx`): the day-navigation group becomes a label/value column like the other figures — the weekday name is a label above the **date field only**, with `[prev][date][next]` on the value row (arrows bottom-aligned via `items-end`, so the weekday never floats above the arrows). The bar aligns all labels on one row and all values beneath, matching the label/value alignment used on the tour pages. `flex-wrap` retained for mobile (FR-015).

**Why not more**: The user asked for these exact fixes and "parts not mentioned should not be changed." No other bar control, figure, or behaviour is modified.

## Testing impact

- `day-layer.test.tsx`: the "nothing selected" case now asserts `data-opacity` `0.75` (was `1`). The highlight case values (1.0 / 0.5) are unchanged, but since the z-order safeguard renders highlighted legs last, that case asserts by colour/opacity membership + highlighted-rendered-last, not by fixed input-leg index. Expected updates for deliberately changed rendering, not new behaviour.
- No backend tests are affected (frozen I/O). No new tests are required; the map's on-load rendering and the removed duplicate line are structural map behaviours jsdom cannot render, so they are verified by the paint-prop assertions plus the manual walkthrough in `quickstart.md`.

## Scope guard

Frontend only. Three components + one test. No dependency, no endpoint, no payload, no data-model change. Everything not listed in the plan's "Files touched" is left exactly as feature 025 shipped it.
