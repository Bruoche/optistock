# Frozen I/O — MUST NOT change

This feature is additive. The following existing interfaces keep their exact behaviour and payloads; their tests stay green unchanged.

## Endpoints (frozen)
- `POST /api/tour/optimize`, `GET /api/tour/status/{job_uuid}` — optimization flow (001/012).
- `POST /api/tour/force` — manual duration fallback (024).
- `POST /api/tour/geometry` — road tracing (002). **Reused** by `use-day-geometry` with **no `tour_id`** (must not finalize totals onto a persisted tour).
- `POST /api/tour/{tour}/assign` — assignment (012).

## Endpoint with ONE deliberate additive change
- `GET /api/tour/drivers` — available drivers (006/013–019). **Not fully frozen**: gains a date-aware, mode-only exclusion (FR-046) so a driver already committed to a mode on the requested date is not offered a different mode there, keeping days single-mode (FR-045). Implemented in `DriverAvailabilityService::rowsFor` (a single `whereDoesntHave` on the `Driver::available` builder before `->get()`); the **`Driver::available` scope signature is unchanged**, so `DriverTest` and the callsite are untouched. Response shape and every other behaviour unchanged. A new test covers the exclusion; existing available-drivers tests stay green as-is. This is the only touch to the assignment flow.

## Shared back-end (reused, not modified)
- `WorkdayEstimator`, `MandatoryBreak`, `TourStartSelector`, `TravelTimeService`, `WorkdayLegsBuilder`, `WorkdayLeg`, `WorkdayEstimate`, `PriorTourLeg`, `TourSegment`, `Coordinate`.
- `DriverTourRepository::priorToursByDriver`, `nextSequence`, `assign` — reused verbatim. New methods (`reorder`, `assignmentsForDay`) are **added**; existing ones unchanged.
- `Tour`, `Stop`, `Driver`, `Warehouse` models — used via existing relations/accessors; no schema change. (`Driver::available` scope gains the FR-046 filter — see the deliberate-change note above.)

## Shared front-end (reused, not modified)
- `TourMap`, `RouteLayer` (+ `TOUR_ROUTE_LAYER_ID`), `ActionButton`, `ConfirmDialog`, `ModeSelect`, `TourDateInput`.
- `types/tour.ts` — `WorkdayLeg`, `DeliveryMode`, `formatDurationHm`, `formatWeekday`, `todayDate` imported as-is.
- `WorkdayLayer`, `WorkdayMarkers`, `DriverList`, `ResultSummary` — **not** modified; the driver page uses new adapted copies (`DayLayer`, `DayMarkers`, `TourList`).

## The one additive touch to existing code
- `TourPageController::edit` + the optimize page gain an **optional** `returnTo` (driver id + date). When absent, behaviour is byte-for-byte the current flow. When present, a successful re-optimize / a back action redirects to `/driver/{id}?date=…` (FR-027/a/b). No change to the edit payload otherwise.

## Data model (frozen)
- No migration. `driver_tour` columns unchanged; reorder writes only `sequence` + `start/end`. `drivers`/`tours`/`stops`/`warehouses` schema unchanged.
