# Feature Specification: Driver Workday Preview

**Feature Branch**: `014-driver-workday-preview`

**Created**: 2026-07-03

**Status**: Draft

**Input**: User description: "As a new feature, we are now going to upgrade the driver assignment. Now, when we click on a driver, instead of having a pop-up asking if we assign the tour to the driver we will show their entire projected itinary for the day with the extra tour so the manager can clearly see what path they are going to make the driver do. And, so, to confirm, since the pop-up will no longer be used, we will instead have a new button to the right of the "New tour" button in the presentation layer. This new button "Assign Driver" will be grayed out at first when no drivers are selected, and be clickable on click. We will only thenn show the pop-up we used. The projected workday tour will use black for the lines of the path that are not part of the current tour to assign, so they are distinguished. If possible, we will also use dotted lines for in-between tour paths. So for example we'd have a dotted black line from warehouse to first tour, then a black line for first tour, then another doted black line to the tour to assign, then a orange line for the currently assignable tour, then a final doted black line from the end of that tour back to the warehouse. Like before, the added lines will first be straight lines to immediatly be shown if we don't already have their polyline data, and then be replaced by actual paths after fetching them. It will be essential that this feature works without breaking if the user cycle through possible drivers quickly."

## Context

Feature 012 made the presentation-phase driver list actionable: clicking a driver
immediately opened a confirmation pop-up, and confirming recorded the assignment.
Feature 013 then redefined a driver's projected day as the full chain — warehouse
to first tour, each tour, the connections between tours, and the return to the
warehouse — but that chain was only ever shown as a **number** (projected hours).

This feature makes the chain **visible**. Clicking a driver no longer jumps
straight to the confirmation pop-up; instead it draws that driver's entire
projected workday on the map — their already-assigned tours for the date, every
connection drive, and the candidate tour slotted into the chain — so the manager
sees the actual path they are about to commit the driver to. The candidate tour
keeps its current highlight color; the rest of the day is drawn in a neutral
color, with connection drives dotted, so the new workload reads at a glance.

Because the pop-up no longer opens on driver click, confirming moves to a new
**"Assign Driver"** button beside the existing "New tour" button: disabled while
no driver is selected, enabled once one is, and opening the same confirmation
pop-up as before when clicked.

The preview must stay correct while the manager rapidly clicks from driver to
driver: connection paths are drawn instantly as straight lines and upgraded to
road-accurate paths when their geometry arrives, and late-arriving geometry for a
previously selected driver must never bleed into the currently shown preview.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Preview a driver's projected workday on the map (Priority: P1)

On the presentation phase, the manager clicks a driver in the list. Instead of a
confirmation pop-up, the map now shows that driver's entire projected day: the
drive from the warehouse to their first tour, each already-assigned tour, the
drives between tours, the candidate tour in its place in the chain, and the final
drive back to the warehouse.

**Why this priority**: This is the feature's core value — the manager sees the
real path the assignment would create instead of judging from a single
projected-hours number.

**Independent Test**: With a driver holding at least one assigned tour for the
date, click that driver and verify the map shows the full chain — warehouse
departure, each of their tours, every connection drive, the candidate tour, and
the warehouse return — matching the same chain (order, start/end stops) used for
the projected-hours figure.

**Acceptance Scenarios**:

1. **Given** the presentation phase with an available-driver list, **When** the manager clicks a driver, **Then** no confirmation pop-up opens and the map displays that driver's projected workday including the candidate tour.
2. **Given** a driver with already-assigned tours for the date, **When** their preview is shown, **Then** it contains, in chain order: the warehouse-to-first-tour connection, each assigned tour, each between-tour connection, the candidate tour in its chain position, and the last-tour-to-warehouse connection.
3. **Given** a driver with no tours yet for the date, **When** their preview is shown, **Then** it contains the warehouse-to-candidate connection, the candidate tour, and the candidate-to-warehouse connection.
4. **Given** a preview is displayed, **When** the manager clicks a different driver, **Then** the preview is replaced by the newly selected driver's projected workday.

---

### User Story 2 - Assign via the "Assign Driver" button (Priority: P1)

A new "Assign Driver" button sits to the right of the "New tour" button. It is
grayed out while no driver is selected. Once the manager selects a driver, the
button becomes clickable; clicking it opens the same confirmation pop-up as
before, and confirming records the assignment exactly as it did in feature 012.

**Why this priority**: Clicking a driver no longer opens the pop-up, so without
this button there is no way to complete an assignment — it is the other half of
the core flow.

**Independent Test**: Enter the presentation phase, verify the button is disabled;
select a driver, verify it enables; click it and verify the familiar confirmation
pop-up opens for that driver, with confirm and cancel behaving as in feature 012.

**Acceptance Scenarios**:

1. **Given** the presentation phase with no driver selected, **When** the manager looks at the controls, **Then** an "Assign Driver" button is visible to the right of the "New tour" button and is grayed out / not actionable.
2. **Given** a driver is selected, **When** the manager clicks the "Assign Driver" button, **Then** the confirmation pop-up opens identifying that driver and the delivery to be assigned.
3. **Given** the confirmation pop-up is open, **When** the manager confirms, **Then** the tour is recorded as assigned to that driver and the manager returns to the cleared route creation menu (feature 012 behavior).
4. **Given** the confirmation pop-up is open, **When** the manager cancels, **Then** no assignment is recorded and the presentation phase remains with the selected driver's preview intact.

---

### User Story 3 - Distinguish the candidate tour from the rest of the day (Priority: P2)

In the preview, the candidate tour keeps its current highlight color while
everything that is not part of it — the driver's already-assigned tours and all
connection drives — is drawn in a neutral color (black). Connection drives
(warehouse legs and between-tour hops) are additionally drawn as dotted lines,
so tours and the driving between them read differently at a glance.

**Why this priority**: The preview is usable without the styling, but the styling
is what makes it legible — the manager must instantly see which part of the path
is the new commitment versus the day they already planned.

**Independent Test**: Show a preview containing at least one prior tour and
verify three visually distinct renderings: the candidate tour in the existing
highlight color, prior tours as solid neutral lines, and connection drives as
dotted neutral lines.

**Acceptance Scenarios**:

1. **Given** a preview is displayed, **When** the manager looks at the candidate tour's path, **Then** it is drawn in the same highlight color a tour currently uses on the presentation map.
2. **Given** a preview is displayed, **When** the manager looks at the driver's already-assigned tours, **Then** their paths are drawn solid in a neutral color clearly distinct from the candidate tour's color.
3. **Given** a preview is displayed, **When** the manager looks at the connection drives (warehouse to first tour, between tours, last tour to warehouse), **Then** they are drawn dotted in the neutral color, distinct from both tour renderings.

---

### User Story 4 - Instant preview that survives rapid driver cycling (Priority: P1)

The preview appears immediately when a driver is clicked: any path segment whose
road-accurate geometry is not yet known is first drawn as a straight line, then
replaced by the real road path once fetched. The manager can click through the
driver list as fast as they like — each click shows the right driver's preview,
and geometry arriving late for a previously viewed driver never corrupts the
current display.

**Why this priority**: The user flagged this as essential — the whole point of the
preview is comparing drivers, which means clicking through them quickly; a
preview that lags, mixes drivers' paths, or breaks under fast cycling defeats the
feature.

**Independent Test**: Click rapidly through several drivers (faster than geometry
fetches complete) and verify the displayed preview always corresponds to the last
clicked driver, straight-line placeholders upgrade in place when their geometry
arrives, and no segment from a previously selected driver remains or appears.

**Acceptance Scenarios**:

1. **Given** a driver is clicked and some path geometry is not yet available, **When** the preview renders, **Then** the missing segments appear immediately as straight lines between their endpoints.
2. **Given** straight-line placeholders are displayed, **When** the road-accurate geometry for a segment arrives, **Then** that segment's straight line is replaced by the real path without disturbing the rest of the preview.
3. **Given** the manager clicks driver A then quickly driver B, **When** geometry requested for A's preview arrives after B was selected, **Then** it does not alter B's displayed preview.
4. **Given** the manager cycles through many drivers rapidly, **When** they stop on one, **Then** the map shows exactly and only that driver's projected workday, with no errors and no leftover segments.

---

### Edge Cases

- **Driver with no tours yet for the date**: The preview is warehouse → candidate tour → warehouse, with the two connection drives dotted; the "Assign Driver" button behaves the same.
- **Re-clicking the currently selected driver**: The driver is deselected — the map reverts to showing only the candidate tour as today, and the "Assign Driver" button grays out again.
- **Geometry fetch fails for a connection or tour segment**: The straight-line depiction remains as the best-effort display, the failure is logged with context, and the preview stays otherwise intact — the preview never blanks or errors out. Assignment stays possible.
- **Re-selecting a driver whose geometry was already fetched**: Already-fetched road paths are reused; the preview does not degrade back to straight lines nor refetch what it already has.
- **Selected date changes or the driver list refreshes while a preview is shown**: The selection is cleared, the preview reverts to the candidate tour only, and the button grays out (the previewed chain would no longer match the refreshed data).
- **Assignment fails on confirm**: Feature 012 behavior holds — the failure is surfaced, nothing partial is recorded, and the manager is not navigated away; the presentation phase and preview remain.
- **Looping vs one-way candidate tour**: The preview's connection points follow the feature 013 start/end-stop rules (looping: start = end, closest stop; one-way: endpoints only), so what is drawn matches what would be recorded on assignment.
- **Confirmation pop-up open**: Only one is active at a time; driver selection cannot change while it is open (feature 012 rule carries over).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: On the presentation phase, clicking a driver MUST select that driver and MUST NOT open the confirmation pop-up directly (superseding the feature 012 click-to-confirm behavior).
- **FR-002**: Selecting a driver MUST display that driver's projected workday on the map: the warehouse-to-first-tour connection, each tour already assigned to them for the selected date, each between-tour connection, the candidate tour in its chain position, and the last-tour-to-warehouse connection.
- **FR-003**: The previewed chain MUST be the same chain used for the driver's projected-hours figure (feature 013): assignment order with the candidate tour appended, connection points at the start/end stops chosen by the feature 013 rules.
- **FR-004**: An "Assign Driver" button MUST be present to the right of the "New tour" button on the presentation phase. It MUST be visibly disabled (grayed out) and non-actionable while no driver is selected, and enabled once a driver is selected.
- **FR-005**: Clicking the enabled "Assign Driver" button MUST open the existing confirmation pop-up for the selected driver. Confirm and cancel MUST behave as in feature 012: confirming records the assignment and returns to the cleared route creation menu; cancelling records nothing and leaves the presentation phase — with the preview still shown — unchanged.
- **FR-006**: In the preview, the candidate tour's path MUST keep the highlight color a tour currently uses on the presentation map, and every path not part of the candidate tour (prior tours and all connection drives) MUST be drawn in a single neutral color clearly distinct from it. Both colors MUST be referenced through the project's role-named palette.
- **FR-007**: Connection drives (warehouse legs and between-tour hops) SHOULD be drawn as dotted lines, while tour paths remain solid, so connections and tours are distinguishable within the neutral-colored set.
- **FR-008**: Any previewed segment whose road-accurate geometry is not yet available MUST render immediately as a straight line between its endpoints, and MUST be replaced in place by the road-accurate path once its geometry is fetched (matching the feature 002 progressive-rendering behavior).
- **FR-009**: Geometry arriving for a driver who is no longer the selected driver MUST NOT modify the displayed preview. The preview shown at any moment MUST correspond exclusively to the most recently selected driver, regardless of how quickly the manager cycles through drivers.
- **FR-010**: Rapid successive driver selections MUST NOT produce errors, mixed previews, leftover segments, or duplicated fetches for geometry already obtained; geometry already fetched in the session MUST be reused when a driver is re-selected.
- **FR-011**: If a segment's geometry cannot be fetched, the system MUST log the failure with context, keep the straight-line depiction as the best-effort display, and keep the rest of the preview and the assignment flow fully functional.
- **FR-012**: Re-clicking the selected driver MUST deselect them: the map reverts to showing only the candidate tour and the "Assign Driver" button returns to its disabled state. A refresh of the driver list or a change of selected date MUST likewise clear the selection and the preview.
- **FR-013**: The preview MUST be display-only: selecting a driver and rendering their projected workday MUST NOT record, modify, or persist any assignment; only confirming the pop-up records one.

### Key Entities *(include if data involved)*

- **Driver selection**: the transient presentation-phase state naming the one driver (if any) whose workday is previewed; it gates the "Assign Driver" button and is cleared on deselect, list refresh, or date change.
- **Projected workday itinerary**: the ordered, display-only sequence of path segments for the selected driver — connection drives and tours, with the candidate tour in its chain position — derived from the same chain as the feature 013 projected day.
- **Path segment**: one drawable piece of the itinerary. Carries its role (candidate tour, prior tour, or connection drive), which determines its rendering (highlight color solid, neutral solid, or neutral dotted), and its geometry state (straight-line placeholder or road-accurate path).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: After clicking a driver, a complete preview (straight-line placeholders permitted) is visible in under 1 second, in 100% of cases.
- **SC-002**: The previewed chain matches the chain behind that driver's projected-hours figure — same tour order and same connection points — in 100% of cases.
- **SC-003**: After rapidly cycling through at least 10 drivers and stopping, the displayed preview corresponds to the last selected driver with zero segments from any other driver, in 100% of trials.
- **SC-004**: In any preview, a viewer can distinguish the candidate tour from the rest of the day by color alone, and (when dotted rendering is in effect) tours from connection drives by line style, in 100% of previews.
- **SC-005**: A manager can complete an assignment in three interactions: select a driver, click "Assign Driver", confirm the pop-up.
- **SC-006**: Zero assignments are recorded from previewing alone — every recorded assignment traces to a confirmed pop-up, 100% of the time.
- **SC-007**: Every failed geometry fetch during previewing is logged, and none results in a blank or broken preview, 100% of the time.

## Assumptions

- **Builds on 012/013**: the driver list, confirmation pop-up, assignment recording, warehouse link, chain order (assignment order, candidate appended last), and start/end-stop selection rules are those of features 012 and 013; this feature changes when the pop-up opens and adds the map preview, nothing about how assignments are computed or recorded.
- **Candidate tour placement**: per feature 013 the candidate is appended after the driver's existing tours, so it is the last tour in the previewed chain, followed only by the return-to-warehouse connection. The user's example (warehouse → first tour → candidate tour → warehouse) matches this ordering.
- **"Orange" means the existing highlight color**: the description's "orange line" is taken as the current tour-highlight color already used on the presentation map, referenced through the project's role-named palette rather than as a new literal color; "black" likewise maps to a neutral palette role.
- **Dotted rendering is best-effort**: the user said "if possible" — dotted connection lines are required only where the map rendering supports it; color separation (FR-006) is the guaranteed distinction.
- **Projected-hours figure unchanged**: the per-driver hours display from features 012/013 stays as is; this feature adds the visual counterpart, not a replacement.
- **Deselection model**: re-clicking the selected driver deselects (toggle); selecting another driver switches the preview. No separate "clear selection" control is introduced.
- **Geometry reuse scope**: fetched road paths are reused for the duration of the presentation session; no cross-session persistence of preview geometry is required.
- **One preview at a time**: the map shows at most one driver's projected workday; comparing two drivers side by side is out of scope.
