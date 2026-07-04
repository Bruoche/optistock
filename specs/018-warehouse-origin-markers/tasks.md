---
description: "Task list for Warehouse & Origin Map Markers"
---

# Tasks: Warehouse & Origin Map Markers

**Input**: Design documents from `specs/018-warehouse-origin-markers/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/warehouse-origin-markers.md

**Tests**: Included — constitution requires tests; plan enumerates them.

**Scope**: Two additive backend fields (from locals already in `DriverController`'s row closure —
no new routing, `projected_seconds` unchanged) + a new frontend marker component rendered on the
selected driver. Minimal blast radius is the explicit goal.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 = warehouse marker, US2 = "0" origin marker

---

## Phase 1: Setup

- [X] T001 Verify baseline green on `018-warehouse-origin-markers`: `npm run format:check`, `npm run lint:check`, `npm run types:check`, `npm run test`, `./vendor/bin/pint --dirty --test`, `php artisan test --filter DriverAvailability` all pass before edits.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Emit the two coordinate fields, plumb them to the `Driver` view model, and add the
marker glyph color role. Blocks US1/US2.

**⚠️ CRITICAL**: `projected_seconds` and the endpoint's routing-call count MUST stay unchanged —
`warehouse_coordinate` and `previous_tour_end` are the existing `$warehouse` / `$incoming` locals,
serialized as `[lat,lng]`; no new preload, query, or fetch.

- [X] T002 In `app/Http/Controllers/DriverController.php`, in the `$driverRows` closure add to the returned array `'warehouse_coordinate' => [$warehouse->lat, $warehouse->lng]` and `'previous_tour_end' => $incoming->isSameAs($warehouse) ? null : [$incoming->lat, $incoming->lng]` (`$warehouse` and `$incoming` are already computed at lines 64/71). Change nothing else (no new preload, no estimator/legs change).
- [X] T003 Extend `tests/Feature/DriverAvailabilityTest.php` (`Http::fake`): assert each row carries `warehouse_coordinate` equal to the driver's warehouse `[lat,lng]`; `previous_tour_end` is `null` for a driver with no prior tour that day, and equals the last prior tour's end `[lat,lng]` for a driver that has one. Assert `projected_seconds` is unchanged for the fixture; do NOT add a redundant call-count assertion — the existing `assertSentCount(...)` guard already catches any new fetch.
- [X] T004 [P] In `resources/js/types/tour.ts`, add **required** `warehouseCoordinate: [number, number]` and `previousTourEnd: [number, number] | null` to `Driver`; in `resources/js/hooks/use-tour-drivers.ts` add `warehouse_coordinate`/`previous_tour_end` to `ApiDriver` and map `warehouse_coordinate → warehouseCoordinate`, `previous_tour_end → previousTourEnd` in the payload `.map(...)`. Because the fields are required (like `legs`/`timeToTour`), update every full-`Driver` fixture so types + tests compile: the `driver()` helper in `resources/js/components/tour/driver-list.test.tsx` and `resources/js/components/tour/result-summary.test.tsx`, the `driver` const in `resources/js/components/tour/assign-driver-dialog.test.tsx`, and the `Driver` object(s) in `resources/js/hooks/use-workday-preview.test.ts` — add `warehouseCoordinate` (a `[lat,lng]`) and `previousTourEnd` (default `null`). This fixture set is exhaustive (verified). No other mapped field changes.
- [X] T005 [P] Extend `resources/js/hooks/use-tour-drivers.test.ts` (mock `fetch`): a payload with `warehouse_coordinate` and `previous_tour_end` (incl. a `null` case) maps onto `Driver.warehouseCoordinate`/`previousTourEnd`.
- [X] T006 [P] In `resources/css/app.css`, add the marker glyph role next to the existing `--route-neutral` lines: `--route-neutral-foreground: #ffffff` in **both** `:root` and `.dark` (theme-stable, like `--route-neutral`), and register `--color-route-neutral-foreground: var(--route-neutral-foreground);` in the `@theme` block beside `--color-route-neutral`. This makes `text-route-neutral-foreground` available for the marker glyph. No other palette change.

**Checkpoint**: API sends the two coordinate fields; `Driver` carries them; the glyph role exists;
total/legs untouched.

---

## Phase 3: User Story 1 - Warehouse marker (Priority: P1) 🎯 MVP

**Goal**: While a driver is selected, a building-icon marker is drawn at that driver's warehouse.

**Independent Test**: Select any driver in the presentation view → a `Building2` circle marker
appears at the warehouse, same size/shape as the numbered stops, `bg-route-neutral/50` fill with a
near-white glyph.

### Tests for User Story 1

- [X] T007 [US1] Add `resources/js/components/tour/workday-markers.test.tsx` (new): rendering `WorkdayMarkers` for a driver with `previousTourEnd: null` draws exactly one marker, positioned at `warehouseCoordinate`, carrying the building glyph and the circle classes (`size-6 rounded-full`, `bg-route-neutral/50`, `text-route-neutral-foreground`); no "0" marker is present.

### Implementation for User Story 1

- [X] T008 [US1] Create `resources/js/components/tour/workday-markers.tsx` exporting `WorkdayMarkers({ driver }: { driver: Driver })`: render a `<Marker>` (react-map-gl/maplibre, `anchor="bottom"`) at `driver.warehouseCoordinate` with a `lucide-react` `Building2` glyph inside the numbered-stop circle utilities (`flex size-6 items-center justify-center rounded-full shadow`), fill `bg-route-neutral/50`, glyph `text-route-neutral-foreground` (no raw color literal). Mount it in `resources/js/pages/tour/optimize.tsx` as `{isDone && selectedDriver && <WorkdayMarkers driver={selectedDriver} />}`, a sibling child of `WorkdayLayer`/`RouteLayer` inside `TourMap`. Do not touch `TourMap` or the numbered stops.

**Checkpoint**: Selecting a driver shows the warehouse marker; markers clear/move with the selection.

---

## Phase 4: User Story 2 - Origin "0" marker (Priority: P2)

**Goal**: For a selected driver with a prior tour that day, a "0" marker is drawn at the end of
that prior tour; for a driver with none, no "0" marker is shown.

**Independent Test**: Select a driver with `previousTourEnd` non-null → a "0" circle marker appears
at that point; select one with `previousTourEnd: null` → only the warehouse marker shows.

### Tests for User Story 2

- [X] T009 [US2] Extend `resources/js/components/tour/workday-markers.test.tsx`: a driver with a non-null `previousTourEnd` renders a second marker labelled "0" at `previousTourEnd`, with the same `bg-route-neutral/50` + `text-route-neutral-foreground` circle styling; a driver with `previousTourEnd: null` renders no "0" marker (only the warehouse).

### Implementation for User Story 2

- [X] T010 [US2] In `resources/js/components/tour/workday-markers.tsx`, after the warehouse marker add a conditional `<Marker>` at `driver.previousTourEnd` (rendered only when `previousTourEnd !== null`) showing the text `0` in the same circle styling (`bg-route-neutral/50`, `text-route-neutral-foreground`) as the warehouse marker. No change to the warehouse marker or to `optimize.tsx`.

**Checkpoint**: Warehouse + conditional "0" marker both work; "0" gated on `previousTourEnd`.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [X] T011 Run the full gate: `npm run format:check`, `npm run lint:check`, `npm run types:check`, `npm run test`, `./vendor/bin/pint --dirty --test`, `php artisan test --filter DriverAvailability` — all green.
- [ ] T012 Run `specs/018-warehouse-origin-markers/quickstart.md` manual checks: warehouse marker on any selected driver; "0" marker only for a driver with a prior tour; both markers' glyphs legible on the fill in light **and** dark themes; `projected_seconds` + route-call count unchanged.

---

## Dependencies & Execution Order

- **Phase 1**: no deps.
- **Phase 2 (Foundational)**: T002 → T003 (test guards the controller change); T004 → T005; T006 (app.css) independent. Backend (T002/T003), frontend plumbing (T004/T005), and the palette role (T006) are all [P] — different files. Blocks US1/US2.
- **US1 (Phase 3)**: after Foundational (needs T004 fields + T006 glyph role). T007 before/with T008.
- **US2 (Phase 4)**: after US1 — extends the same `workday-markers.tsx` T008 creates. T009 before/with T010.
- **Polish (Phase 5)**: after US1 + US2.

## Parallel Opportunities

- Backend (T002/T003) ∥ frontend plumbing (T004/T005) ∥ palette role (T006) — different files.
- US1 and US2 both edit `workday-markers.tsx` (+ its test) → sequential, not parallel with each other.

## Implementation Strategy

- **MVP** = Phase 1 + Phase 2 + Phase 3 (US1): API sends the two coords, the glyph role exists, and
  selecting a driver shows the warehouse marker. US2 (Phase 4) then adds the conditional "0" origin
  marker.
- Commit after each task/logical group; keep `projected_seconds` + route-call count green throughout.
