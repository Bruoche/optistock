# Implementation Plan: Driver Management Page

**Branch**: `025-driver-management-page` | **Date**: 2026-07-23 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/025-driver-management-page/spec.md`

## Summary

A new authenticated page at `/driver/{driver}` for managing one driver: an editable identity block (picture, name, delivery modes, warehouse), a day bar (prev/next-day + date field, day workday figures, a "Tour order" save), a map identical to the tour-result map but showing the day's already-assigned tours (all neutral, no candidate) with a warehouse marker and "T1/T2/T3…" tour-start markers, and an independently scrolling list of the day's tours (per-tour durations, hover/select highlight, unfold-to-stops on selection, drag-handle reorder, per-row Edit).

Technical approach: **maximise reuse of the 013–019 workday machinery and the 002/014 map+lazy-trace pipeline**; copy-and-adapt only where the existing code is candidate-tour-centric. The page loads once from a single `GET /api/driver/{driver}/day?date=` endpoint that returns the driver identity, the ordered day tours (with stops + durations), the day workday totals, and the neutral drawable legs — the same wire shapes the driver-list rows and workday preview already use, so the front-end reuses `WorkdayLeg`, `formatDurationHm`, the lazy-trace hook pattern, `TourMap`, and `RouteLayer` unchanged. Three writes are new: driver-detail update (multipart), tour-order recompute-and-save (blocking, with a force fallback), each thin `Controller → Service → Repository`. Front-end fallbacks/spinners mirror the tour page; drag reorder uses a newly added `@dnd-kit/sortable`.

## Technical Context

**Language/Version**: PHP 8.3 (Laravel 12), TypeScript 5 / React 19 (Inertia 3, `react-map-gl` 8 / MapLibre)

**Primary Dependencies**: Existing — Laravel, Inertia, react-map-gl, lucide-react, Tailwind v4. **New** — `@dnd-kit/core` + `@dnd-kit/sortable` (accessible, touch-capable drag reorder; nothing comparable is installed).

**Storage**: MySQL/SQLite via Eloquent. Tables reused unchanged: `drivers`, `warehouses`, `delivery_modes`, `driver_delivery_mode`, `tours`, `stops`, `driver_tour` (pivot with `date`, `start/end_lat/lng`, `sequence`). **No migration.**

**Testing**: PHPUnit (feature + unit), Vitest + Testing Library (jsdom — media queries and MapLibre GL are not evaluated, so map assertions stay at the prop/data layer as in existing `workday-layer.test.tsx`).

**Target Platform**: Web (desktop + mobile, viewport 320–2560 px).

**Project Type**: Web application (Laravel back-end + Inertia/React front-end in one repo).

**Performance Goals**: Page shell with placeholders < 1 s (SC-002); tour-selection map/stop update < 300 ms for loaded data (SC-004); routing calls kept to the existing preloaded-batch count — the day endpoint reuses `TravelTimeService::preload` so one day = one capped batch, not N per-leg fetches.

**Constraints**: No regression to the frozen optimize/geometry/drivers/assign I/O (023/024 contracts). External routing failures degrade to straight lines + "unavailable" (never a false 0). Colours only through role-named palette variables (Constitution VI). Reorder recompute needs routing; blocked on failure with a force-save escape hatch.

**Scale/Scope**: One driver, one day at a time; a day is a handful of tours (bounded by a driver's working hours). Reuses batched, de-duplicated routing.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Quality First** — PASS. New services/controllers get feature + unit tests; the frozen endpoints and their existing tests are untouched. No behaviour change to shared code (see Reuse Safety below).
- **II. Readable by Default** — PASS. Intent-named verbs/nouns mirroring 013–019 (`DayWorkdayService::summaryFor`, `TourOrderService::reorder`). Comments only for the non-obvious (why a reorder blocks on routing; why force-save exists). No narration.
- **III. Simple & Transparent** — PASS. One read endpoint feeds the whole page; each write is a single-responsibility service. The candidate-tour concept is dropped, not special-cased. Adapted builders are new classes, not flags bolted onto the candidate ones — avoids widening shared code.
- **IV. Robustness as Standard** — PASS. Every backend-sourced value has a loading + unavailable fallback (FR-036/037); routing failures logged by the existing `TravelTimeService`/`OpenStreetRouteClient`; reorder never persists a partially-recomputed order; out-of-order responses discarded (FR-039). Force-save logs the degraded path.
- **V. Performance with Clarity** — PASS. Reuses `preload` batching and the per-load lazy-trace cache; no per-leg N+1 routing, no per-row queries (stops/assignments loaded grouped, as `DriverTourRepository` already does).
- **VI. Consistent, Reusable Front-End Styling** — PASS. Reuses existing role colors (`--primary`, `--secondary`, `--route-neutral`, `--accent`, `text-on-color`) and shared components (`ActionButton`, `ConfirmDialog`, `TourDateInput`, `ModeSelect`). New shared row-highlight/label classes match the tour pages; no off-palette values.

**Post-Phase-1 re-check**: PASS — design introduces no new violations (no shared-behavior edits; new files + additive page prop only).

### Reuse Safety (no-regression conditions)

| Reused as-is | Why safe |
|---|---|
| `WorkdayEstimator`, `MandatoryBreak`, `TourStartSelector`, `TravelTimeService`, `PriorTourLeg`/`TourSegment`/`WorkdayLeg`/`WorkdayEstimate` | Pure/stateless-per-request; called with new inputs only. No signature change. |
| `DriverTourRepository::priorToursByDriver`, `nextSequence` | Read/query methods reused verbatim for the day load; new write method **added** (`reorder`), existing `assign` untouched. |
| `TourMap`, `RouteLayer`, `formatDurationHm`, `formatWeekday`, `todayDate`, `use-tour-geometry` composition, `ActionButton`, `ConfirmDialog`, `ModeSelect`, `TourDateInput`, frontend `DELIVERY_MODES` constant (mode list + labels — the identity/mode selectors read this, so **no `modes` page prop is needed**; only `warehouses` is a prop) | Imported, not modified. |
| `Driver` model relations (`warehouse`, `deliveryModes`, `tours`) | Used as-is by the new page. Detail-update writes go through a new request/service. |

| Modified shared behaviour (deliberate, tested) | Change |
|---|---|
| `DriverAvailabilityService::rowsFor` (NOT the `Driver::available` scope) | **Un-frozen** for one additive rule (FR-046): a `whereDoesntHave('tours'…)` chained onto the `Driver::available` builder before `->get()` excludes a driver whose existing assignments on the selected `date` are a mode other than the requested one, so a day stays single-mode (FR-045). `rowsFor` already holds `date`; the `Driver::available` scope signature is left unchanged so `DriverTest` + the callsite are untouched. All other available-drivers behaviour is unchanged; a new test covers the exclusion. This is the only touch to the assignment flow. |

| Copy-and-adapt (new file, original untouched) | Reason it can't be reused directly |
|---|---|
| `WorkdayLegsBuilder` → `DayLegsBuilder` | Original hard-codes warehouse→priors→**candidate**→warehouse with 2 highlighted brackets. The day view has no candidate: every tour is a solid leg, every connection neutral, and highlight is driven by *which tour is selected on the client*, not baked server-side. |
| `WorkdayLayer` → `DayLayer` | Original highlights via a server `highlight` flag on 2 legs. Day view highlights the *selected tour's* tour-leg + its two bracketing connections, chosen client-side by tour index. |
| `WorkdayMarkers` → `DayMarkers` | Original draws warehouse + "0" origin. Day view draws warehouse + one "T{n}" marker per tour start. |
| `ResultSummary`/`DriverList` row styling → `TourList`/`TourRow` | Same hover-secondary/select-primary visual, different content (tour durations, unfold-to-stops, drag handle, Edit). Shared visual extracted to a small class, not the whole component. |

## Project Structure

### Documentation (this feature)

```text
specs/025-driver-management-page/
├── plan.md              # This file
├── research.md          # Phase 0 — decisions + reuse map
├── data-model.md        # Phase 1 — entities, wire shapes, recompute rules
├── quickstart.md        # Phase 1 — how to run/verify
├── contracts/
│   ├── driver-day.md        # GET day payload
│   ├── driver-update.md     # driver-detail write (multipart)
│   ├── tour-order.md        # reorder recompute + force-save
│   └── frozen-io.md         # endpoints/props that MUST NOT change
└── checklists/requirements.md
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/
│   │   ├── DriverPageController.php      # NEW  GET /driver/{driver} (Inertia)
│   │   ├── DriverController.php          # +new method: day() JSON  (existing available() untouched)
│   │   ├── DriverUpdateController.php    # NEW  PATCH driver detail
│   │   └── TourOrderController.php       # NEW  POST reorder (+force)
│   └── Requests/
│       ├── DriverDayRequest.php          # NEW  date validation
│       ├── UpdateDriverRequest.php       # NEW  name/image/modes/warehouse
│       └── ReorderToursRequest.php       # NEW  ordered tour_ids + force flag
├── Services/
│   ├── DayWorkdayService.php             # NEW  day totals + rows (reuses WorkdayEstimator/MandatoryBreak)
│   ├── DayLegsBuilder.php                # NEW  (adapted from WorkdayLegsBuilder)
│   └── TourOrderService.php              # NEW  recompute entry/exit for a sequence (reuses TourStartSelector)
├── Repositories/
│   └── DriverTourRepository.php          # +reorder() write; +assignmentsForDay() read helper
├── DTOs/
│   └── DriverDayData.php                 # NEW  assembles the day payload
└── Models/  (unchanged)

resources/js/
├── pages/driver/
│   └── manage.tsx                        # NEW  the page
├── components/driver/
│   ├── driver-identity-bar.tsx           # NEW  editable identity + Update
│   ├── day-bar.tsx                       # NEW  prev/next + date + figures + Tour-order Update
│   ├── day-layer.tsx                     # NEW  (adapted from workday-layer)
│   ├── day-markers.tsx                   # NEW  warehouse + T{n}
│   ├── tour-list.tsx                     # NEW  sortable list
│   ├── tour-row.tsx                      # NEW  row + unfold stops + drag handle + Edit
│   └── tour-stop-markers.tsx             # NEW  numbered stops of selected tour (or reuse TourMap markers)
├── hooks/
│   ├── use-driver-day.ts                 # NEW  day fetch, cancellation, fallback state
│   └── use-day-geometry.ts               # NEW  lazy-trace legs (pattern of use-workday-preview)
└── types/driver.ts                       # NEW  DriverDay, DayTour, DayStop view models

routes/web.php   # +GET /driver/{driver}
routes/api.php   # +GET /api/driver/{driver}/day, PATCH /api/driver/{driver}, POST /api/driver/{driver}/tour-order
```

**Structure Decision**: Existing Laravel + Inertia/React layout. Feature code lives beside the tour feature under matching folders (`components/driver`, `pages/driver`), keeping the starter-kit boundary intact. New services/controllers follow the established Controller→Service→Repository roles from 023.

## Key implementation decisions (from clarification)

0. **Day is single-mode** (FR-045/046): the driver-page day `mode` is derived from the day's tours (guaranteed to agree). The invariant is enforced upstream in the existing available-drivers flow — a driver already committed to a mode on a date is not offered a different mode there. This makes every connection-drive and reorder-recompute mode unambiguous. It is the one deliberate change to shared assignment behaviour (see the Modified-shared-behaviour table); it is additive, date-aware for mode only, and covered by updated + new tests.
1. **Single day-data read** (user directive): `GET /api/driver/{driver}/day?date=` returns identity + ordered tours + workday summary + neutral legs in one response. The page renders its frame with fallbacks immediately and fills in on resolve. Day switching re-fetches with request cancellation (stale responses discarded, FR-039), exactly like `use-tour-drivers`. Page props are `driverId`, `initialDate`, `warehouses` only — the mode list comes from the frontend `DELIVERY_MODES` constant (no `modes` prop; the `DeliveryMode` enum carries no labels).
2. **Day load uses *stored* entry/exit** from the `driver_tour` pivot (chosen at assignment or last reorder) — no recompute on read. Recompute happens only on reorder.
3. **Reorder = recompute + block-with-force** (CL-001 + planning Q): `POST tour-order` recomputes each tour's entry/exit for the new sequence by chaining (incoming = warehouse, then previous tour's end) and persists new `sequence` + `start/end` per pivot row in one transaction. **Failure detection is explicit**: `TourStartSelector::select` silently falls back to the first candidate on all-null durations, so the service does **not** infer routing health from it — it preloads the chain's connections via `TravelTimeService::preload` and measures them directly; any null → **blocked (422)**, nothing persisted. The client then reveals a **force-save** that re-POSTs `force:true`, which selects each entry/exit by a **routing-free** rule (lowest-position candidate — no API calls at all, so it can't re-trip the dead service) and persists order + those points. Conflict (FR-034): the submitted tour-id set must equal the driver's current assignment set for the date, else 409 + refresh.
4. **Driver-detail update**: multipart `PATCH` (name required, ≥1 mode required, warehouse_id must exist, optional image upload to `public` disk like the driver image already is). A **warehouse change** triggers a fixed client-side `ConfirmDialog` advisory before submit (FR-007a); no per-assignment enumeration. Existing assignments are never rewritten.
5. **Edit round-trip**: the row Edit navigates to the existing `tour/{tour}/edit`, passing a `return_to` (driver id + date). On successful re-optimize the optimize page redirects back there (FR-027); a back/cancel returns without saving (FR-027a); failure stays on the edit screen (FR-027b). This is the one touch to the existing optimize flow — additive (`editTour.returnTo?`), no behaviour change when absent.
6. **Drag reorder**: `@dnd-kit/sortable` vertical list; a single-tour day disables the handles and the Tour-order Update stays disabled (FR-009-analog / edge case).

## Complexity Tracking

> No constitution violations requiring justification. One new runtime dependency (`@dnd-kit`) is the standard accessible-DnD choice and is scoped to the tour list; a hand-rolled native-drag alternative was rejected for poor touch/mobile + accessibility behaviour that the spec (mobile reorder, FR-042) requires.
