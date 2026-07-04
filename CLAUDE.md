<!-- SPECKIT START -->
For additional context about technologies to be used, project structure,
shell commands, and other important information, read the current plan:
`specs/019-driver-mandatory-breaks/plan.md` (fold legally-mandated rest breaks into the Projected workday — `break = max(workdayBreak[0/30/45min by 6h/9h day], drivingBreak[45min × floor(driving/4h30)])`, driving = day total − all stop seconds; new pure `MandatoryBreak::secondsFor(workdayS, drivingS)` reused for the day with + without the candidate; `WorkdayEstimate` gains `drivingDurationS`, `TourSegment`/`PriorTourLeg` gain `stopSecondsS`; `projected_seconds` now includes `breakWith`, new `added_break` field = breakWith − breakWithout drives a conditional orange "+Required break" figure; counterfactual adds one preloaded `lastPriorEnd→warehouse` connection; design artifacts: `research.md`, `data-model.md`, `contracts/driver-mandatory-breaks.md`, `quickstart.md`). Prior features:
`specs/018-warehouse-origin-markers/plan.md` (when a driver is selected, draw two map point markers — a warehouse `Building2` marker and a "0" marker at the end of the last prior tour, shown only when `previous_tour_end` is non-null — same size as numbered stop markers, `--route-neutral` at 50% opacity; adds additive `warehouse_coordinate` + `previous_tour_end` fields to the drivers row from locals already in the closure, no new routing; new `WorkdayMarkers` component; design artifacts: `research.md`, `data-model.md`, `contracts/warehouse-origin-markers.md`, `quickstart.md`). Prior features:
`specs/017-driver-road-times/plan.md` (driver rows show two grey road-time figures — `time_to_tour` + `time_from_tour`, read from the preloaded connection cache so `projected_seconds` + routing-call count stay unchanged — left of the renamed "Total projected workday" total; only `DriverController`'s row closure changes backend-side;
design artifacts: `research.md`, `data-model.md`, `contracts/driver-road-times.md`, `quickstart.md`). Prior features:
`specs/016-tour-confirm-and-mode/plan.md` (frontend-only: confirm pop-up before "New tour" drops the on-going tour + a delivery-mode selector in the result view that reloads the driver list; shared `ConfirmDialog`, `presentationMode` page state),
`specs/015-projected-path-emphasis/plan.md` (recolor the candidate tour's two bracketing connection legs to primary orange + dim other workday legs to 50%; adds a `highlight` flag to each leg),
`specs/014-driver-workday-preview/plan.md` (map preview of a selected driver's projected workday legs + "Assign Driver" button),
`specs/013-inter-tour-travel/plan.md` (warehouse origin + chained driver workday incl. inter-tour travel & start/end stop selection),
`specs/012-tour-driver-assignment/plan.md` (assign tours to drivers + persist tours/stops),
`specs/011-weekday-label/plan.md` (driver schedule filtering & selected-weekday label),
`specs/008-containerized-deployment/plan.md` (containerized deployment),
`specs/007-stop-duration/plan.md` (per-stop delivery duration & tour duration total),
`specs/006-driver-assignment/plan.md` (delivery driver assignment),
`specs/005-header-theme-menu/plan.md` (header brand & theme menu),
`specs/004-tour-loop-toggle/plan.md` (tour loop toggle),
`specs/003-delivery-mode-selection/plan.md` (delivery mode selection),
`specs/002-road-accurate-route-tracing/plan.md` (road-accurate route tracing) and
`specs/001-delivery-route-optimization/plan.md` (delivery route optimization — complete).
<!-- SPECKIT END -->
