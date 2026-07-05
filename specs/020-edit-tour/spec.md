# Feature Specification: Edit Tour

**Feature Branch**: `020-edit-tour`

**Created**: 2026-07-05

**Status**: Draft

**Input**: User description: "As a new feature, we are now going to allow editing routes. For now this will be done before attribution. A new button will be added inbetween \"New Tour\" and \"Assign Driver\" for \"Edit\". We will also rename the two afromentionned buttons \"New\" and \"Assign\". So now we will have the buttons \"New\", \"Edit\" and \"Assign\" in that order. The Edit button will return back to the tour optimization menu, but with all the coordinates and options selected to be edited. Optimizing this route will modify the tour in the database and not create a new one."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Edit an optimized tour before assigning it (Priority: P1)

A planner has optimized a tour and is looking at the result view, but realizes a stop is in the wrong place, a delivery duration is off, or the wrong mode/loop/date was used. Instead of discarding everything and rebuilding from scratch, they click **Edit** to return to the optimization menu with every stop coordinate, per-stop duration, and every option (mode, loop, date) exactly as they were. They adjust what they need and re-optimize. The result replaces the same tour rather than leaving a duplicate behind.

**Why this priority**: This is the core of the feature — the entire value proposition is correcting an existing tour without losing work or accumulating orphaned tour records. Without it there is no feature.

**Independent Test**: Optimize a tour, click Edit, confirm the editing view shows the same stops/durations/options, change one stop, re-optimize, and confirm the same tour (same identity) now reflects the change with no second tour created.

**Acceptance Scenarios**:

1. **Given** an optimized tour shown in the result view, **When** the planner clicks Edit, **Then** the optimization menu reopens pre-populated with that tour's stops, per-stop durations, and the mode, loop, and date it was optimized with.
2. **Given** the editing menu opened via Edit, **When** the planner changes stops or options and re-optimizes, **Then** the existing tour is updated in place and no additional tour is created.
3. **Given** an edited tour has been re-optimized, **When** the result view reopens, **Then** it reflects the edited stops and options.

---

### User Story 2 - Relabeled action buttons (Priority: P2)

In the result view the planner sees three action buttons in a consistent, compact order — **New**, **Edit**, **Assign** — replacing the previous "New tour" and "Assign Driver" labels. The shorter labels keep the three actions readable side by side.

**Why this priority**: The rename and ordering are required by the feature but are cosmetic relative to the editing capability; the editing flow (P1) delivers value even if labels were unchanged.

**Independent Test**: Open the result view and confirm exactly three buttons labeled "New", "Edit", "Assign" appear in that left-to-right order.

**Acceptance Scenarios**:

1. **Given** an optimized tour shown in the result view, **When** the planner looks at the action row, **Then** the buttons read "New", "Edit", "Assign" in that order.
2. **Given** the relabeled buttons, **When** the planner clicks "New", **Then** the same new-tour confirmation and reset behavior as before occurs.
3. **Given** the relabeled buttons, **When** the planner clicks "Assign", **Then** the same driver-assignment flow as before occurs.

---

### Edge Cases

- **Editing down to too few stops**: If the planner removes stops during editing so fewer than the minimum (2) remain, re-optimizing is blocked the same way it is when building a new tour.
- **Abandoning an edit**: If the planner opens Edit but then clicks New, the in-progress edit is discarded and a fresh empty tour begins (subject to the existing new-tour confirmation).
- **Re-optimize with no changes**: Re-optimizing an edited tour without changing anything updates the same tour and yields an equivalent result (no duplicate tour).
- **Assign is unavailable while editing**: Because editing returns to the pre-result menu, the Assign action is not offered until the tour is (re-)optimized.
- **Driver preview/selection during edit**: Any previewed-driver selection from the result view is cleared when entering the editing menu, since the driver list belongs to the result view.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The result view MUST present three action buttons in the order New, Edit, Assign.
- **FR-002**: The button previously labeled "New tour" MUST be relabeled "New" while keeping its existing confirm-and-reset behavior.
- **FR-003**: The button previously labeled "Assign Driver" MUST be relabeled "Assign" while keeping its existing driver-assignment behavior.
- **FR-004**: The Edit button MUST return the planner to the tour optimization menu.
- **FR-005**: On entering the editing menu, the system MUST pre-populate the stop coordinates, per-stop delivery durations, and the mode, loop, and date the tour was optimized with.
- **FR-006**: While editing, the planner MUST be able to change stops (add, remove, reorder via the existing controls) and options exactly as when building a new tour.
- **FR-007**: Re-optimizing a tour opened via Edit MUST update that existing tour in place and MUST NOT create a new tour.
- **FR-008**: Re-optimizing an edited tour MUST NOT leave the original pre-edit tour as a separate persisted record.
- **FR-009**: Editing MUST be available only before the tour is assigned to a driver (before attribution).
- **FR-010**: The same minimum-stop and validation rules that gate optimizing a new tour MUST gate re-optimizing an edited tour.
- **FR-011**: After a successful re-optimization, the result view MUST reopen reflecting the edited stops and options, ready for assignment.

### Key Entities *(include if feature involves data)*

- **Tour**: The persisted optimized route (created when first optimized). It carries a stable identity that editing preserves — a re-optimization from the editing menu updates this same record's stops and options rather than inserting a new one. Remains unassigned throughout the edit flow.
- **Stop**: A coordinate on the tour with a per-stop delivery duration. Editing restores and can modify the full set of stops.
- **Tour options**: The mode, loop, and date the tour was optimized with; restored into the editing controls and updatable before re-optimization.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A planner can correct a single stop on an existing tour and re-optimize without re-entering any of the other stops or options.
- **SC-002**: Editing and re-optimizing a tour results in exactly one persisted tour for that route (no duplicates), verifiable by tour count before and after.
- **SC-003**: 100% of the tour's stops, durations, mode, loop, and date are restored into the editing menu when Edit is clicked.
- **SC-004**: The result view shows exactly three actions labeled New, Edit, Assign in that order.
- **SC-005**: A planner can go from viewing a result to an editable, pre-populated menu in a single click.

## Assumptions

- Editing applies only to tours in the result view (optimized but not yet assigned); once a tour is assigned to a driver it leaves this flow, so no separate "edit an assigned tour" path is in scope.
- The client still holds the tour's stops (coordinates and per-stop durations) and the mode/loop/date it was optimized with from the current session, so restoring them into the editing menu does not require re-fetching the tour.
- Re-optimization reuses the existing optimize action and its validation, differing only in that it targets the existing tour's identity for the update instead of creating a new record.
- The Edit action is disabled or absent in states where there is no optimized tour to edit (e.g., while an optimization is still pending).
- Reordering of stops, if needed, uses the existing stop-list controls; no new editing affordances beyond those already available when building a tour are introduced.
- "Before attribution" is interpreted as "while the tour is unassigned"; assignment (via Assign) ends the edit-eligible window for that tour.
