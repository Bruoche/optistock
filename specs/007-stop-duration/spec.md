# Feature Specification: Per-Stop Delivery Duration & Tour Duration Total

**Feature Branch**: `007-stop-duration`

**Created**: 2026-06-10

**Status**: Draft

**Input**: User description: "As a new feature, we should now be able to specify a duration for each delivery at each coordinate in a tour. For example, a tour with 2 points might have a 10 minutes delivery time at the first stop then 20 for the second stop. The durations will be given by the user in the list of coordinates. A new numeric field will be added to the part presenting the coordinates, that has a default value of 10 minute on each new stop created. Then, when the total is calculated, we will still have the current value that now will be presented as 'time on road' but another new similar time field will be added showing 'tour duration', that will be the on-road duration + the total of the stops duration."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Set a delivery duration per stop (Priority: P1)

A planner building a tour wants to record how long the driver will spend at each stop (unloading, handing over a parcel, signature). In the list of stops/coordinates, each stop shows an editable numeric duration in minutes. New stops start at a default of 10 minutes, and the planner can change any of them to reflect a quick drop or a long delivery.

**Why this priority**: This is the new input the whole feature depends on. Without per-stop durations there is nothing to total. It delivers value on its own — even before any new total is shown, the planner can capture and review intended time-on-site per stop.

**Independent Test**: Add several stops, confirm each defaults to 10 minutes, edit individual values, and confirm the edited values persist on each stop and are not lost when other stops are added or removed.

**Acceptance Scenarios**:

1. **Given** an empty tour, **When** the planner adds a new stop, **Then** that stop shows a duration field pre-filled with 10 minutes.
2. **Given** a stop with the default 10 minutes, **When** the planner changes its duration to 20 minutes, **Then** the stop retains 20 minutes and other stops are unaffected.
3. **Given** a tour with several stops carrying different durations, **When** the planner removes one stop, **Then** the remaining stops keep their own durations unchanged.

---

### User Story 2 - See time on road and total tour duration (Priority: P2)

After optimizing a tour, the planner wants to distinguish driving time from the full elapsed time including stops. The result now shows two figures: "Time on road" (the existing computed driving/travel duration) and "Tour duration" (time on road plus the sum of every stop's duration).

**Why this priority**: This turns the captured per-stop durations into the decision-useful output — the realistic total a tour will take. It builds on US1 but is independently testable once stop durations exist.

**Independent Test**: With a tour whose travel time and individual stop durations are known, confirm "Time on road" equals the travel time and "Tour duration" equals travel time plus the sum of all stop durations.

**Acceptance Scenarios**:

1. **Given** an optimized tour with a travel time of 44 minutes and stops of 6, 10, and 24 minutes, **When** the result is shown, **Then** "Time on road" reads 44 min and "Tour duration" reads 84 min (1 h 24 min).
2. **Given** an optimized tour where every stop duration is 0 minutes, **When** the result is shown, **Then** "Tour duration" equals "Time on road".
3. **Given** an optimized tour, **When** the planner changes a stop's duration and re-optimizes (or the total recalculates), **Then** "Tour duration" updates to reflect the new sum while "Time on road" reflects only the recomputed travel time.

---

### Edge Cases

- A stop with a duration of 0 minutes contributes nothing to the tour duration (tour duration then equals time on road).
- When travel time is unavailable (e.g. a tour too small to produce road metrics), "Time on road" shows its existing "unavailable" treatment; tour duration handling for that case is defined in FR-011.
- The planner clears the duration field or enters a non-numeric / negative value — the system must keep a valid, non-negative number rather than break the total.
- Very large durations (e.g. a multi-hour stop) must still total and display correctly in hours-and-minutes form.
- A tour with a single stop totals correctly (time on road + that one stop's duration).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The stop/coordinate list MUST present an editable numeric duration field, in minutes, for every stop.
- **FR-002**: Each newly created stop MUST default to a duration of 10 minutes.
- **FR-003**: Planners MUST be able to edit any stop's duration independently, without affecting other stops' durations.
- **FR-004**: A stop's duration MUST be preserved when other stops are added or removed and while the tour list is otherwise edited.
- **FR-005**: Stop durations MUST be constrained to non-negative whole minutes; invalid, empty, or negative input MUST be coerced to or rejected in favor of a valid value (default behavior defined in Assumptions).
- **FR-006**: The result summary MUST relabel the existing computed travel/driving duration as "Time on road" (this is the value currently labeled "Tour duration").
- **FR-007**: The result summary MUST display a new "Tour duration" figure equal to the time on road plus the sum of all stop durations.
- **FR-008**: "Tour duration" MUST recalculate whenever stop durations or the computed travel time change, so the two figures stay consistent with the current tour.
- **FR-009**: Both figures MUST be presented in the same human-readable time format already used for the existing total (minutes, and hours + minutes when an hour or more).
- **FR-010**: Stop durations MUST be carried with the tour data so the totals are computed from the same durations the planner entered for each stop.
- **FR-011**: When the computed travel time is unavailable, the system MUST still present the sum of stop durations as the tour duration (treating unavailable travel time as a zero contribution) rather than showing the tour duration as unavailable.

### Key Entities *(include if feature involves data)*

- **Stop (coordinate)**: A single delivery point in a tour. Gains a new attribute: delivery duration in minutes (non-negative whole number, default 10).
- **Tour result**: The computed outcome for a tour. Gains a derived "tour duration" = time on road + sum of all stop durations, alongside the existing travel duration now labeled "time on road".

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A planner can set or change a stop's delivery duration in 3 or fewer interactions per stop, with the entered value visible on that stop immediately.
- **SC-002**: Every new stop appears with a 10-minute duration without the planner taking any action.
- **SC-003**: For any tour, "Tour duration" equals "Time on road" plus the exact sum of the listed stop durations, verifiable by hand for 100% of test tours.
- **SC-004**: Editing a stop duration is reflected in the "Tour duration" total within the same interaction cycle (no manual refresh needed beyond the existing recalculation flow).
- **SC-005**: Both "Time on road" and "Tour duration" are simultaneously visible and clearly distinguishable in the result summary.

## Assumptions

- **Duration unit and type**: Durations are whole minutes (integers). The existing travel time, internally tracked in seconds, is summed with stop minutes by converting consistently; display rounds the same way the current total does.
- **Default value**: Every stop — including the first — defaults to 10 minutes; there is no separate non-delivery origin/depot stop in the current model (stops are a flat numbered list), so all stops carry a duration.
- **Invalid input handling**: Empty, non-numeric, or negative entries fall back to **0** (non-integers are floored); the fallback must never let the total become NaN or negative. (Decided: `0`, not reset-to-default, so clearing a field reads as "no time here" rather than silently re-imposing 10.)
- **Example arithmetic in the prompt**: The prompt's worked example states a tour duration of "1 h 03 (63 min)" for a 44-minute drive with stops of 6 + 10 + 24 minutes; that sum is 40, so the correct tour duration is 84 minutes (1 h 24 min). The unambiguous, twice-stated formula — tour duration = time on road + sum of stop durations — governs; the 63 figure is treated as a miscalculation in the prompt, and the spec uses 84.
- **Recalculation flow**: "Tour duration" reuses the existing recalculation/optimize cycle; no new live-refresh mechanism beyond what already updates the displayed total is assumed.
- **Persistence**: Stop durations live with the tour's stop data wherever stops are already held for a session; no new long-term storage requirement is introduced beyond mirroring how stops are currently kept.
- **Upper bound**: a sane per-stop ceiling of **1440 minutes (24 h)** is enforced (blocks absurd/overflow input); realistic multi-hour deliveries sit well under it and must still total and format correctly. Values above the ceiling are rejected (422), not silently clamped.
