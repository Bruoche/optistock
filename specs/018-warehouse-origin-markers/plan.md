# Implementation Plan: Warehouse & Origin Map Markers

**Branch**: `018-warehouse-origin-markers` | **Date**: 2026-07-04 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/018-warehouse-origin-markers/spec.md`, building on features
013 (`WorkdayEstimator`, `incomingPoint`, warehouse origin), 014 (`useWorkdayPreview`, workday
legs), 015 (`WorkdayLayer` neutral/highlight lines), 016 (result-view driver list + selection).

## Summary

When a driver is selected in the presentation view, draw two extra point markers on the map:
a **warehouse** marker (building icon) at the driver's warehouse, and a **"0"** marker at the end
of the driver's last prior tour that day — shown only when the driver has a prior tour (else the
driver departs from the warehouse and the "0" is suppressed). Both are circles the same size/shape
as the numbered stop markers, rendered in the neutral near-black role at 50% opacity.

Coordinates come from **two additive fields on the existing drivers endpoint** — both are values
already held as locals in `DriverController`'s row closure (`$warehouse`, `$incoming`), serialized
as `[lat,lng]` pairs; `previous_tour_end` is `null` when the incoming point equals the warehouse.
No new routing call, no new query, no change to `projected_seconds`, legs, or ordering. Frontend
maps the two fields and renders markers as children of the map, gated on the selected driver.

## Technical Context

**Stack**: Laravel 12 (PHP) + React 19 + Inertia + Tailwind v4 + shadcn/ui + react-map-gl/maplibre +
lucide-react; PHPUnit (`Http::fake`) + Vitest/Testing Library.

**Existing pieces reused (unchanged behavior)**:
- `DriverController::incomingPoint` (013) — last prior tour's `end`, else `warehouse->coordinate`.
- `Coordinate` (`lat`/`lng`, `isSameAs`) — the null gate for `previous_tour_end`.
- `TourMap` (`<Map>` + numbered stop `<Marker>`s) — new markers render as sibling children in the
  same map context; the numbered-stop styling (`size-6 rounded-full … shadow`) is the shape reused.
- `WorkdayLayer` / `RouteLayer` / `useWorkdayPreview` — **not touched** (lines unchanged).
- `--route-neutral` (#1a1a1a, theme-stable) — the neutral near-black role already used for workday
  lines; reused for the markers' fill (Constitution VI, no new palette entry).

**Project Type**: web app (Laravel + React SPA).

**Performance/Constraints**: zero added `/route` calls (both coords are locals already computed);
`projected_seconds` and route-call count byte-for-byte unchanged; additive payload (two coord fields).

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — new surfaces covered: `DriverAvailabilityTest` extended
  (`warehouse_coordinate` = the driver's warehouse; `previous_tour_end` null with no prior tour and
  = the last prior tour's end when one exists; **`projected_seconds` + route-call count unchanged**);
  `use-tour-drivers` (maps both fields incl. null); new `WorkdayMarkers` (warehouse marker always;
  "0" only when `previousTourEnd` non-null). PASS.
- **II/III. Readable & Simple** — backend serializes two locals already in the closure (no new
  service/query); frontend adds one small presentational component reading two `Driver` fields.
  Single responsibility; the map component stops needing to know leg internals. PASS.
- **IV. Robustness** — `previous_tour_end` null is the explicit "came from warehouse" signal
  (never a misplaced marker); a missing coord skips its marker rather than defaulting a position.
  No new failure path (no routing added). PASS.
- **V. Performance with Clarity** — no new routing/query; two scalar pairs added to the payload;
  markers are cheap DOM overlays gated on selection. PASS.
- **VI. Consistent, Reusable Styling** — markers reuse the numbered-stop shape utilities and the
  `--route-neutral` role at 50% opacity; the glyph uses a new companion role
  `--route-neutral-foreground` added to the palette (the sanctioned single-source mechanism), not a
  raw literal. Re-theming stays a one-place edit. PASS.

No violations. (Complexity Tracking omitted.)

## Decisions

Full rationale + alternatives in [research.md](research.md); condensed:

- **D1 — Serialize `$warehouse` and `$incoming` (both already locals in the row closure) as two
  additive fields; do not derive coords on the frontend from `legs[0].path`.** Server-side is where
  the "incoming = warehouse when no prior tour" invariant already lives (`incomingPoint`); a
  frontend derivation would couple the map to implicit leg ordering. Smallest, self-documenting
  change; mirrors how 017 added fields to this same closure. (research D1)
- **D2 — `previous_tour_end` = `null` when `incoming->isSameAs(warehouse)`**, else `[lat,lng]`;
  `warehouse_coordinate` always `[lat,lng]`. Null is the "0"-marker gate — decided in one place
  server-side. (research D2)
- **D3 — Markers reuse the numbered-stop circle utilities (`size-6 rounded-full … shadow`), fill
  `bg-route-neutral/50` (50% on the fill only, glyph stays full-opacity); warehouse shows a lucide
  `Building2` glyph, the other shows "0".** The neutral role matches the workday lines and the
  requested black-50%. The glyph needs a light color that reads on the dark fill in both themes and
  no existing role qualifies, so add a theme-stable companion **`--route-neutral-foreground`**
  (near-white, both themes) via app.css and use `text-route-neutral-foreground` — Constitution VI's
  single-source mechanism, no raw literal. (research D3)
- **D4 — Render markers as children of `TourMap`** (a new `WorkdayMarkers` component), gated on
  `isDone && selectedDriver`, alongside `WorkdayLayer`/`RouteLayer`; `TourMap` and the numbered
  stops are untouched. (research D4)

## Project Structure (feature-specific)

Backend — **change (one file, one closure)**:
- `app/Http/Controllers/DriverController.php` — in the `$driverRows` closure add
  `'warehouse_coordinate' => [$warehouse->lat, $warehouse->lng]` and
  `'previous_tour_end' => $incoming->isSameAs($warehouse) ? null : [$incoming->lat, $incoming->lng]`
  to the returned array. Nothing else changes (`$warehouse`, `$incoming` already computed).

Frontend — **change**:
- `resources/css/app.css` — add the `--route-neutral-foreground: #ffffff` role (identical in
  `:root` and `.dark`) + its `--color-route-neutral-foreground` registration, next to the existing
  `--route-neutral` lines (the marker glyph color).
- `resources/js/types/tour.ts` — `Driver` gains `warehouseCoordinate: [number, number]` and
  `previousTourEnd: [number, number] | null`.
- `resources/js/hooks/use-tour-drivers.ts` — map `warehouse_coordinate`/`previous_tour_end`.
- `resources/js/components/tour/workday-markers.tsx` — **new**: given the selected `Driver`, render
  the warehouse `<Marker>` (Building2 glyph) and, when `previousTourEnd` is non-null, the "0"
  `<Marker>`; reuse the stop-marker circle utilities + `--route-neutral`/50%.
- `resources/js/pages/tour/optimize.tsx` — render `{isDone && selectedDriver && <WorkdayMarkers
  driver={selectedDriver} />}` as a child of `TourMap`.

Tests: `tests/Feature/DriverAvailabilityTest.php` (extend — new fields, null vs prior-tour case,
unchanged total + call count), `resources/js/hooks/use-tour-drivers.test.ts` (extend — mapping incl.
null), `resources/js/components/tour/workday-markers.test.tsx` (**new** — warehouse always; "0" only
when `previousTourEnd` non-null; both use the neutral 50% styling).

Out of scope: any change to the workday lines, driver ordering, selection, road-time figures (017),
or assignment; breaking out connections between a driver's earlier tours.

## Flow

1. `GET /api/tour/drivers` responds with each existing row **plus** `warehouse_coordinate` and
   `previous_tour_end` (`[lat,lng]` / `null`) — from locals already computed; nothing else changes.
2. `useTourDrivers` maps them onto `Driver` (`warehouseCoordinate`, `previousTourEnd`).
3. On a selected driver, `WorkdayMarkers` draws the warehouse marker always and the "0" marker only
   when `previousTourEnd` is non-null; markers clear/move with the selection.

## API contracts (this run)

- `GET /api/tour/drivers?mode&date&tour` — response gains `warehouse_coordinate` (`[lat,lng]`) and
  `previous_tour_end` (`[lat,lng]|null`). Request, validation, all prior fields, and routing-call
  count unchanged. See `contracts/warehouse-origin-markers.md`.

## Design Artifacts (this run)

- `research.md` — reused slice + decisions D1–D4 (incl. why not frontend leg-derivation).
- `data-model.md` — additive API fields + frontend view-model + marker display + invariants (no DB change).
- `contracts/warehouse-origin-markers.md` — the two added fields + null semantics + marker mapping.
- `quickstart.md` — verification incl. the null/prior-tour cases and the `projected_seconds`/call-count guard.

---

Generated by speckit.plan on 2026-07-04
