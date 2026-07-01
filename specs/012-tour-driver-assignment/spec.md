# Feature Specification: Tour Driver Assignment

**Feature Branch**: `012-tour-driver-assignment`

**Created**: 2026-07-01

**Status**: Draft

**Input**: User description: "As a new feature, we are now going to allow assigning the optimized routes to drivers. Now we will be able to click on drivers in the list in the presentation menu to assign them the delivery. A confirmation pop-up appearing to validate we want to assign it to that driver. After assigning the delivery to a driver the user is sent back to the route creation menu. Now drivers will also have the total hours they will be working if doing this delivery next to their other informations, so managers can know if they are going over intended daily hours by assigning the route. For now the total hours is just the total durations of all the tours assigned to the driver. Implementing breaks and the time to travel inbetween tours will be implemented later."

## Context

Since feature 006 the presentation phase lists the drivers able to run the just-optimized tour, and feature 011 narrows that list to those scheduled on the tour's selected date. Until now the list has been read-only. This feature makes it **actionable**: the dispatcher/manager can pick a driver from the list to actually assign the delivery to them, confirm the choice, and the tour is recorded against that driver. To support the decision, each driver row now also shows the **total hours they would be working** if given this delivery — their already-assigned time for the day plus this tour — so the manager can see whether the assignment pushes them past a reasonable daily workload.

## Clarifications

### Session 2026-07-01

- Q: When the tour's road travel duration is unknown (a 2-point tour that makes no routing call, or an API/trace failure that yields no duration), how must it be stored and exposed so the frontend can later detect the case? → A: Persist `travel_duration_s` as **NULL**, never coerced to 0 — at persistence and at every payload the frontend receives; the `Tour` total-duration accessor **propagates null** (unknown) rather than `?? 0`, so "unknown" stays distinct from a genuine "zero travel time".
- Q: To prepare for the out-of-scope manual-entry fallback, add a duration-source marker now or keep it minimal? → A: **Minimal** — a plain nullable `travel_duration_s`, no source column. NULL = unknown is signal enough, and a later manual-entry endpoint simply sets the field. Record that plan D5's "never trust a client-sent duration" carries a **planned exception** for this explicit manual-fallback path so the persistence layer must not preclude a trusted server-side setter.
- Q: What must happen if the optimized route cannot be persisted (a save error)? → A: **Notify the user.** A persist failure on either the cache-hit or the background-job path surfaces as a clear failure (toast/notification), never a silently-unsaved route; assignment is not offered without a saved tour. The expensive optimization result stays cached so a retry re-attempts only the save (FR-014).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Assign the tour to a driver (Priority: P1)

On the presentation phase, the manager clicks a driver in the list to assign them
the just-optimized delivery. A confirmation pop-up asks them to validate the
choice; on confirming, the tour is recorded as assigned to that driver and the
manager is returned to the route creation menu, ready to plan the next tour.

**Why this priority**: This is the core deliverable — turning the driver list from
an informational display into the point where a delivery is actually handed to a
driver.

**Independent Test**: Optimize a tour, click a driver, confirm the pop-up, and
verify the tour is recorded against that driver and the view returns to the empty
route creation menu.

**Acceptance Scenarios**:

1. **Given** the presentation phase with an available-driver list, **When** the manager clicks a driver, **Then** a confirmation pop-up appears naming that driver and the delivery to be assigned.
2. **Given** the confirmation pop-up, **When** the manager confirms, **Then** the tour is recorded as assigned to that driver and the manager is returned to the route creation menu.
3. **Given** an assignment has just completed, **When** the route creation menu is shown, **Then** it is cleared and ready for a new tour (no leftover stops or result).

---

### User Story 2 - See each driver's projected working hours (Priority: P1)

Beside each driver's existing information (name, mode icons), the list shows the
total hours that driver would work if given this delivery — the sum of the
durations of the tours already assigned to them for the selected date plus the
current tour — so the manager can judge whether assigning it pushes the driver
over their intended daily hours.

**Why this priority**: The projected-hours figure is what lets the manager make an
informed assignment; without it the click is blind. It is delivered alongside the
assignment action.

**Independent Test**: With a driver already holding one or more assigned tours for
the date, view the list and confirm their displayed hours equal the sum of those
tours' durations plus the current tour's duration, in a readable format.

**Acceptance Scenarios**:

1. **Given** a driver with no tours yet assigned for the date, **When** the list is shown for a tour of duration D, **Then** that driver's projected hours equal D.
2. **Given** a driver already assigned tours totalling T for the date, **When** the list is shown for a tour of duration D, **Then** that driver's projected hours equal T + D.
3. **Given** a driver's projected hours, **When** displayed, **Then** they appear in a human-readable hours/minutes format consistent with the tour-duration figure elsewhere on the page.

---

### User Story 3 - Cancel the confirmation without assigning (Priority: P2)

If the manager opens the confirmation pop-up but changes their mind, they can
cancel it; no assignment is made and they stay on the presentation phase with the
list intact.

**Why this priority**: Guards against accidental assignments; important for trust
but secondary to the assign path itself.

**Independent Test**: Click a driver, cancel the pop-up, and confirm no tour was
recorded and the presentation phase is unchanged.

**Acceptance Scenarios**:

1. **Given** the confirmation pop-up is open, **When** the manager cancels (or dismisses) it, **Then** no assignment is recorded and the pop-up closes.
2. **Given** the pop-up was cancelled, **When** the presentation phase is shown, **Then** the tour is still displayed and the driver list is unchanged.

---

### Edge Cases

- What if assigning would push the driver over their intended daily hours? The assignment is still allowed; the projected-hours figure is informational only and no hard limit is enforced in this feature.
- What if the assignment fails to be recorded (e.g., a save error)? The failure is surfaced to the manager (they are not silently returned to the creation menu), and no partial assignment is left behind.
- What if the route itself cannot be saved (a database error while persisting the tour or its stops)? The whole persist is one transaction (no partial tour), the failure is logged with context, and the user is **notified** that the route could not be saved — they are not shown an unsaved route as if it were assignable. A retry re-attempts the save; the optimization result is cached, so the slow upstream call is not repeated.
- What if the manager clicks a second driver while a confirmation is already open? Only one confirmation is active at a time; resolving it (confirm or cancel) precedes any further action.
- What is shown for a driver with no assigned tours yet? Their projected hours equal just the current tour's duration.
- Does changing the selected date change the projected hours? Yes — the projected total reflects the tours assigned for the currently selected date, so it updates with the date (and with the refreshed list).
- What if both the initial estimate and the geometry trace yield no road travel duration (a 2-point tour, or the routing API/trace failing)? The travel duration is recorded and surfaced as **unknown** (null), never as zero. The tour stays usable — stops, ordering, and assignment all work — and the travel figure reads as unavailable. This null state is the exact hook a later manual-duration-entry field (out of scope) will detect to appear only in that case.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: On the presentation phase, each driver entry MUST be clickable to initiate assigning the current tour to that driver.
- **FR-002**: Clicking a driver MUST open a confirmation pop-up that identifies the driver and the delivery to be assigned, with confirm and cancel actions.
- **FR-003**: Confirming MUST record the current tour as assigned to that driver, retaining the tour's date and its duration.
- **FR-004**: After a successful assignment, the system MUST return the manager to the route creation menu, cleared and ready for a new tour.
- **FR-005**: Cancelling or dismissing the confirmation MUST make no assignment and leave the presentation phase and its list unchanged.
- **FR-006**: Each driver entry MUST display the driver's projected total working hours for the selected date — the sum of the durations of tours already assigned to that driver for that date plus the current tour's duration.
- **FR-007**: The tour duration used for the projection and the recorded assignment MUST be the tour's total duration as shown on the presentation phase (travel time plus per-stop delivery durations).
- **FR-008**: Projected hours MUST be shown in a human-readable hours/minutes format consistent with the existing tour-duration figure.
- **FR-009**: The projected-hours figure MUST update when the selected date changes (reflecting that date's assigned tours) and stay consistent with the refreshed driver list.
- **FR-010**: Assignment MUST be permitted even when the projected hours exceed any notion of intended daily hours; no hard cap is enforced in this feature.
- **FR-011**: If recording the assignment fails, the system MUST surface the failure to the manager and MUST NOT record a partial assignment nor navigate away as if it succeeded.
- **FR-012**: When a tour's road travel duration cannot be determined (a 2-point tour with no routing call, or an API/trace failure), the system MUST represent it as an explicit **unknown** value (null) — distinct from a genuine zero — and MUST preserve that distinction end-to-end: it is persisted as null (never coerced to 0) and reaches the frontend as null in the tour payloads. The derived total duration MUST likewise report unknown rather than substituting zero for the missing travel time.
- **FR-013**: The design MUST NOT preclude a later manual travel-duration entry: `travel_duration_s` remains a plain writable nullable field, and the "no client-sent duration is trusted" rule carries a planned exception for a future trusted server-side setter of a user-supplied duration. The manual-entry field/endpoint itself is **out of scope** for this feature; only the detectable null state and the unblocked write path are required now.
- **FR-014**: If the optimized tour cannot be persisted — a save error on the synchronous cache-hit path or inside the background job — the system MUST surface the failure to the user with a clear notification rather than presenting an unsaved route, MUST log it with context, and MUST NOT offer an unsaved route for assignment. The persist is atomic (no partial tour/stops), and the optimization result MAY be retained (cached) so a retry re-attempts only the save without repeating the slow upstream call.

> **Scope note (FR-012)**: "report unknown rather than substituting zero" is required at the **data/model/payload** layer (nullable `travel_duration_s`, null-propagating total accessor, raw null in the tour payload). Surfacing "unknown" in the on-screen tour-duration figure ships with the out-of-scope manual-entry field; this feature only guarantees the null state is present and detectable.

### Key Entities *(include if data involved)*

- **Assignment**: the link recording that a specific tour is handed to a specific driver, for the tour's date, carrying the tour's total duration. Relates a Driver to a Tour.
- **Tour**: the optimized delivery run — its ordered stops, selected date, and total duration. Becomes a persisted record when assigned to a driver (previously it existed only transiently during planning). Its **road travel duration is nullable**: null denotes an unknown/undetermined road time (no routing call or an API/trace failure), kept distinct from a genuine zero.
- **Driver**: unchanged in its own attributes (name, image, modes, schedule) but gains a derived **projected working hours** for a given date — the sum of durations of that date's assigned tours plus the tour under consideration.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A manager can assign a tour to a driver in at most two interactions (click the driver, confirm the pop-up).
- **SC-002**: After confirming an assignment, the manager lands on the cleared route creation menu 100% of the time.
- **SC-003**: For any listed driver, the displayed projected hours equal the sum of that date's already-assigned tour durations plus the current tour's duration, in 100% of cases.
- **SC-004**: Cancelling the confirmation results in zero assignments recorded and no navigation, 100% of the time.
- **SC-005**: A recorded assignment persists and is reflected in the driver's projected hours on subsequent tours for the same date.
- **SC-006**: An unknown road travel duration is distinguishable from a zero at the API boundary in 100% of cases — a null travel/total duration is never coerced to 0 before reaching the client.
- **SC-007**: A persistence failure is surfaced to the user in 100% of cases — no route is ever presented as assignable without a successful save, and every persist failure is logged.

## Assumptions

- **Daily scope**: "total hours" is scoped to the tour's selected date — the projected figure sums the tours assigned to the driver **for that date**, matching the "daily hours" concern (not an all-time total).
- **Includes the prospective tour**: the displayed figure is the driver's projected load "if doing this delivery", i.e. already-assigned durations for the date **plus** the current tour's duration.
- **Duration source**: a tour's duration is its total duration from the presentation phase (road/travel time from feature 002 plus per-stop delivery durations from feature 007); breaks and travel time between successive tours are explicitly **out of scope** for now.
- **No contracted-hours data**: this feature does not introduce a per-driver "intended daily hours" limit; the manager reads the projected figure and decides. Enforcing or configuring a daily cap is out of scope.
- **Tour becomes persisted on assignment**: assigning is the point at which the previously transient, client-side tour is recorded; unassigned optimized tours are not persisted by this feature.
- **One driver per tour**: a tour is assigned to a single driver. Reassigning, unassigning, or splitting a tour across drivers is out of scope.
- **Assignable set**: only drivers shown in the list (already filtered by mode and the date's weekday per features 006/011) can be assigned; the click acts on that filtered list.
- **Builds on 006/011**: the clickable list, its placement, and the date/weekday context are those established in features 006 and 011.
- **Manual travel-duration fallback (out of scope, prepared-for)**: when the routing API is unavailable and travel duration is unknown, a later feature will let the user enter it by hand in a field shown **only** in that case, so the app stays usable without the API. That field/endpoint is not built here. Prepared-for means only: travel duration is stored nullable (null = unknown, never zeroed), the null reaches the frontend so the case is detectable, and `travel_duration_s` stays a plain writable column a future trusted setter can populate. No duration-source column is added now.
