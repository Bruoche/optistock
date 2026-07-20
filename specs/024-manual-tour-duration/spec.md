# Feature Specification: Manual Tour Duration Fallback

**Feature Branch**: `024-manual-tour-duration`

**Created**: 2026-07-08

**Status**: Draft

**Input**: User description: "We are now going to add the ability to use the application when the external API is unavailable. Now, when tour optimization fails, a new field will appear to allow writing yourself the duration of the tour manually. The optimisation menu will have on the top bar a new option with a number/duration field allowing to select the number of minutes / the duration of the tour. With it will be added a new button \"Force Tour\" that'll save the tour in the current order of the coordinates with the tour duration given manually. Likewise, the back-end will just use saved tour duration for calculations when possible (if that's not already the case) and should I believe already give you an estimate without the in-between tour durations if traces are unavailable. We will make sure that if any request fail the features keep working without it to the best possible result while still always giving transparent warning on data that couldn't be obtained."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Force a tour when optimization is unavailable (Priority: P1)

A dispatcher has placed stops on the map, but the automatic route optimization cannot run — the external routing service is down, times out, or otherwise returns a failure. Instead of being blocked, the dispatcher types the tour's expected duration into a duration field on the top bar and presses **Force Tour**. The application saves the tour with the stops kept in the order they were entered and records the duration the dispatcher supplied. The forced tour behaves like any saved tour: it can be reviewed and assigned to a driver.

**Why this priority**: This is the core value of the feature — it turns a hard dead-end (optimization failed, nothing saved, work blocked) into a usable result. Without it the application is unusable whenever the external service is unavailable.

**Independent Test**: With the routing service made to fail (or unreachable), place two or more stops, confirm the duration field appears only after the failure, enter a duration, press **Force Tour**, and confirm a tour is saved in the entered stop order carrying that drive duration and is offered for driver assignment.

**Acceptance Scenarios**:

1. **Given** stops are placed and automatic optimization has failed, **When** the dispatcher enters a duration and presses **Force Tour**, **Then** a tour is saved with the stops in their current (entered) order and with the supplied duration, and the result view is shown.
2. **Given** the dispatcher has not entered a duration (field empty or zero/invalid), **When** they press **Force Tour**, **Then** the tour is not saved and the dispatcher is told a valid duration is required.
3. **Given** a tour was forced with a manual duration, **When** the dispatcher proceeds to driver assignment, **Then** the forced tour is selectable and its saved duration is used in the driver workday figures.
4. **Given** the dispatcher is editing an existing unassigned tour and optimization fails, **When** they force the tour with a manual duration, **Then** that same tour is updated in place (stops replaced in current order, duration set) rather than a duplicate being created.

---

### User Story 2 - Transparent warnings when data can't be obtained (Priority: P2)

Whenever a piece of information cannot be retrieved from an external service — the optimized order, the on-road route line, the total distance, an inter-tour travel time, or a driver-workday figure — the dispatcher continues to get the best result the application can still produce, and is clearly told which piece is missing or was supplied manually. Nothing is silently dropped or silently guessed: a forced tour is visibly marked as manually entered, a partial route is visibly marked as incomplete, and an unknown figure is shown as unknown rather than as a confident zero.

**Why this priority**: Trust. A best-effort result is only safe to act on if the dispatcher knows exactly which parts are estimated, manual, or missing. This makes the degraded modes (including the forced tour) honest.

**Independent Test**: Force each external dependency to fail one at a time and confirm that in every case (a) the flow still completes with a usable result and (b) a visible warning names the specific data that could not be obtained.

**Acceptance Scenarios**:

1. **Given** a tour was saved with a manually forced duration, **When** the dispatcher views the result and the driver workday, **Then** the duration is visibly indicated as manually entered rather than measured.
2. **Given** the road route for one or more segments could not be traced, **When** the dispatcher views the route, **Then** the tour still renders and the untraceable segments are visibly indicated as approximate/incomplete rather than omitted without notice.
3. **Given** one or more inter-tour travel times or workday figures could not be obtained, **When** the dispatcher views a driver's projected workday, **Then** the workday is shown as an incomplete/best-effort estimate rather than as a precise total.
4. **Given** any external request fails, **When** the failure is handled, **Then** it is recorded (logged) with enough context to diagnose it, and never swallowed silently.

---

### User Story 3 - Best-effort driver workday from the saved duration (Priority: P3)

When the application computes a driver's projected workday, it uses each tour's saved duration (including a manually forced one) for that tour's own time, and fills in the between-tour and to/from-warehouse travel times where they are available. If some of those travel times cannot be obtained, the workday is still produced from the parts that are known, clearly flagged as incomplete, rather than failing outright.

**Why this priority**: This makes the forced tour (P1) actually usable downstream and generalizes the existing best-effort estimation so a missing travel time never blocks driver assignment. It depends on P1 producing a saved duration.

**Independent Test**: With a forced tour (manual duration) and one unobtainable inter-tour travel time, request the driver workday and confirm it returns a figure built from the saved duration plus the known travel times, flagged incomplete.

**Acceptance Scenarios**:

1. **Given** a tour has a saved duration (measured or manually forced), **When** the driver workday is computed, **Then** that saved duration is used for the tour's own contribution to the day.
2. **Given** a between-tour or warehouse travel time cannot be obtained, **When** the driver workday is computed, **Then** the missing leg contributes no phantom time and the workday total is flagged as an incomplete estimate.

---

### Edge Cases

- **Optimization succeeds**: If automatic optimization returns a result, the normal optimized flow is used and the manual-duration field / Force Tour action are not shown at all.
- **Retry then success**: If a first optimization fails (field revealed) and a subsequent retry succeeds, the normal optimized result governs; the manual field need not persist once a valid optimized result exists.
- **Duration of zero, blank, negative, or non-numeric**: rejected with a clear message; no tour is saved.
- **Very large duration**: accepted up to a sensible upper bound; beyond it, rejected with a clear message rather than stored as an implausible value.
- **Fewer than the minimum / more than the maximum stops**: the same stop-count rules that govern optimization also govern a forced tour; forcing does not bypass them.
- **Forcing a tour produces no distance / no route line**: distance and on-road geometry are shown as unknown/approximate (not zero), consistent with the transparency rule.
- **The tour being edited has vanished** (e.g. deleted elsewhere) when a forced save is attempted: the dispatcher is told the save could not be completed rather than a silent new tour being created.
- **Partial optimization data**: if the order is obtained but totals are not (or vice versa), the missing pieces follow the same unknown/warning treatment.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: When an optimization request returns an error (routing service down, timeout, or failure), the tour-optimization screen's top bar MUST reveal a duration input that lets the dispatcher enter the tour's total drive duration in minutes. The field MUST NOT be present while optimization has not failed — manual entry is a fallback, not the default path.
- **FR-002**: Alongside the revealed duration field, the top bar MUST provide a **Force Tour** action that saves the current stops as a tour using the manually entered drive duration, without requiring the external optimization to have succeeded.
- **FR-003**: The manual-duration field and **Force Tour** action MUST appear only in the optimization-failure state, so the dispatcher gains the fallback exactly when blocked and is not offered manual entry when optimization is working.
- **FR-004**: A forced tour MUST be saved with its stops in the dispatcher's current (entered) order, without reordering.
- **FR-005**: A forced tour MUST record the manually entered value as the tour's total drive duration (the figure automatic optimization would otherwise have produced). Per-stop delivery/service durations MUST be saved with the tour unchanged, exactly as they are today — the manual value replaces only the drive duration, never the per-stop durations.
- **FR-006**: The system MUST reject a Force Tour attempt whose duration is missing, zero, negative, non-numeric, or above the accepted upper bound, and MUST tell the dispatcher why, without saving a tour.
- **FR-007**: A forced tour MUST enforce the same stop-count and coordinate validity rules that apply to an optimized tour.
- **FR-008**: When the dispatcher is editing an existing unassigned tour, forcing MUST update that tour in place (replacing its stops in current order and setting its duration) rather than creating a duplicate; if the target tour no longer exists, the dispatcher MUST be told the save failed rather than a new tour being silently created.
- **FR-009**: A forced (or otherwise saved) tour MUST be usable in the downstream driver-assignment flow exactly like an optimized tour.
- **FR-010**: Driver-workday calculations MUST use each tour's saved duration — including a manually forced one — as that tour's own contribution.
- **FR-011**: When some inter-tour or warehouse travel times cannot be obtained, the driver-workday calculation MUST still produce a best-effort result from the known values and MUST flag it as an incomplete estimate rather than presenting missing legs as zero or failing outright.
- **FR-012**: When the on-road route of one or more segments cannot be traced, the tour MUST still be shown, with the untraceable segments visibly indicated as approximate/incomplete rather than omitted without notice.
- **FR-013**: A value that could not be obtained (total distance, an untraced segment, an unknown travel time) MUST be presented to the dispatcher as unknown/approximate, never as a confident zero.
- **FR-014**: A manually forced duration MUST be visibly distinguishable in the interface from a measured one, so the dispatcher always knows the figure was entered by hand.
- **FR-015**: Every handled external-service failure MUST be recorded (logged) with enough context to diagnose it — the operation, the relevant identifiers, and the error detail — and MUST NOT be swallowed silently.
- **FR-016**: Every degraded path (optimization failure, trace failure, travel-time failure, distance unavailable) MUST leave the rest of the feature working to the best result still obtainable, without cascading into a total failure of the screen.

### Key Entities *(include if data involved)*

- **Tour**: A saved delivery route for a user, made of ordered stops, carrying a total duration and (when known) a total distance. A tour may be produced by automatic optimization or by a manual force; in both cases it is assignable to a driver. The duration may be measured or manually entered, and which one it is must be distinguishable.
- **Stop**: A single coordinate with its own delivery/service duration, held in a position order within a tour. For a forced tour the position order is the order the dispatcher entered.
- **Manual duration**: The tour duration a dispatcher types when forcing a tour, expressed in minutes, used in place of a measured routing duration for that tour.
- **Driver workday estimate**: The projected day for a driver built from the tour's saved duration plus available travel times; carries a flag indicating whether every component was known (complete) or some were missing (incomplete/best-effort).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: When the external routing service is unavailable, a dispatcher can still save a usable tour and reach the driver-assignment step, with zero hard dead-ends in the flow.
- **SC-002**: A dispatcher can save a forced tour in under 30 seconds from the moment optimization fails (enter duration, press Force Tour, see the saved result).
- **SC-003**: In 100% of cases where a piece of data cannot be obtained, the dispatcher sees an explicit indication of what is missing or manual; no figure is silently shown as zero.
- **SC-004**: 100% of handled external-service failures produce a diagnostic log entry; none are swallowed silently.
- **SC-005**: A forced tour's manually entered duration is the exact value used for that tour's contribution to the driver workday, matching what the dispatcher typed.
- **SC-006**: With any single external dependency failing, the corresponding user flow still completes with a best-effort result rather than an error screen.

## Assumptions

- The manual-duration field and **Force Tour** action are revealed in the tour-optimization top bar ONLY after an optimization request returns an error; they are hidden while optimization has not failed. Manual entry is a fallback for a dead external service, not a normal way to build a tour.
- The manually entered duration represents the tour's total drive duration — the same quantity the routing service would have reported as the tour's total duration, previously available only from the optimization request. Per-stop delivery/service durations are unaffected: they never came from the external service and are saved with the tour exactly as today; the manual value fills only the tour table's drive-duration entry that the dead API would otherwise have supplied.
- The manual duration is entered and displayed in **minutes** (whole minutes), consistent with how tour duration is surfaced elsewhere in the interface; it is stored internally in the unit the rest of the system already uses.
- "Current order of the coordinates" means the order in which the dispatcher placed/entered the stops (input order), with no optimization reordering.
- The existing best-effort behaviors already in the system — unknown workday legs counting as zero-but-flagged, untraceable route segments kept as straight/approximate, geometry totals reported only when every leg succeeded — are the baseline this feature builds on and generalizes; this feature does not weaken them.
- Endpoint contracts and existing behavior for the successful-optimization path are unchanged; this feature is additive (a new manual path plus clearer degraded-mode signaling), not a change to how a successful optimization works.
- Forcing a tour does not attempt to call the external routing service for distance or geometry; those remain unknown/approximate for a forced tour until (and unless) they can be obtained by the normal trace path.
- The upper bound for an accepted manual duration is a sensible operational maximum (e.g. a plausible single-day driving ceiling); the exact number is a detail for planning, not a scope decision.
