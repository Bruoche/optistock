# Feature Specification: Driver Management Page

**Feature Branch**: `025-driver-management-page`

**Created**: 2026-07-23

**Status**: Draft

**Input**: User description: "We are now going to add a new page for a new feature of managing delivery drivers. This new page will now sit at the end-point /driver/{id} and will allow managing a specific drivers. At the top we will have the base driver infos, with their icon and name, available modes of transportation and their assigned warehouse. The page allow for the administrator (current user) to change these infos, and to the right of these fields is a greyed-out "Update" button that saves the edits made, becoming available when an edit is done to the afromentionned drivers data. Then, under this, will be another bar allowing to select a day, with arrows for next/precedent day and an opened date field in the center. To the left will be all the informations of the workday (total work day, driven time, stop time, break durations). To the right will be a button to save edits to tour order ("Update" button with a label "Tour order" over it for exemple, this label having to be always aligned with other similar labels as they are in the tour pages). Under this bar, will be a map that's near identical to the route's presentation map. This map will show the expected tours for the driver that day. So, it will show in a black ligns all the tours that are intended for the driver, and in black dotted lines the path to and back from the warehouse and inbetween tours. There will be initially a marker showing the warehouse's position, and a marker at the start of each tour saying "T" + the tour number (1 for the first, 2 for the first, so in order we'd have the markers "T1", "T2", "T3", etc.) This entire map's functionment will be identical to the map shown in the route presentation page (after optimization), except there will be no "projected" tour with special treatment since we are simply seeing the already assigned tours. Under this map, we will have a list showing the tours assigned that day in order. They will each have the infos of the tour (total duration, driven duration, stops duration), and clicking on them allow selecting the tour (same presentation as for the driver list, each option is highlighted in the secondary color on hover and primary orange when selected). They will also each have an "Edit" button sending to the Edit tour page, so that info can be modified. Once and edit is confirmed we're sent back to precedent page (in this case to the driver management page). When a tour is selected, it will be highlighted on the map in primary orange, as well as the dotted lines coming to and from that tour, the numbered stops also showing on the map like in the route presentation page. The selected tour will also unfold to show each stops in the tour, showing their 'index' (1 for first, 2 for second and so on), coordinate and stop duration for each. These indexes being meant to corelate with the number shown in the stops list, which in themselves correspond to the order in which the stops are made. All the tours in the list will have a handle on the very left allowing draging them to change the order. When doing so the "Update" button on the top bar will un-gray an allow updating the tour order. They also scroll independently so that we can keep seeing the top bar while scrolling through the tours. Like with the tour page, everything must have a fallback value when waiting / not recieving data from the back-end (and in the case of the external API not responding) list / similar potentially slow data without fallback should also have spinners showing the app is awaiting for said data. Everything must be reactive and keep working even when the user quickly select through tours and other such stress cases. The entire interface must be responsive, allowing to be shown correctly on mobile like it does in desktop."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See a driver's planned workday for a chosen day (Priority: P1)

A planner opens a specific driver's management page and picks a date. They immediately see who the driver is (picture, name, the delivery modes the driver can run, the driver's home warehouse), the shape of that day's work on a map (every tour assigned to the driver that day, plus the drives from the warehouse, between tours, and back to the warehouse), and the day's totals (total workday, driven time, time spent at stops, mandatory break time). Below the map they see the day's tours listed in the order the driver will run them, each with its own total, driven, and stop durations.

**Why this priority**: This is the core value of the page — a single place to answer "what is this driver doing on this day, and is the day reasonable?". Without it nothing else on the page has a subject. It stands alone as a useful read-only planning view.

**Independent Test**: Open the page for a driver who has tours assigned on a given date; verify the header identity block, the day's four workday figures, the map drawing every assigned tour plus the connecting drives, the warehouse marker, the "T1/T2/T3…" tour-start markers, and the ordered tour list with per-tour durations.

**Acceptance Scenarios**:

1. **Given** a driver with three tours assigned on 2026-07-23, **When** the planner opens the driver's page for that date, **Then** the map shows three tour paths in the neutral colour with solid lines, the drives warehouse→tour1, tour1→tour2, tour2→tour3 and tour3→warehouse as dotted neutral lines, one warehouse marker, and markers "T1", "T2", "T3" at the point where the driver enters each tour.
2. **Given** the same driver and date, **When** the page finishes loading, **Then** the day bar shows Total workday, Driven time, Stop time and Break time, each as a duration, and the tour list shows the three tours in running order with each tour's total, driven and stop durations.
3. **Given** a date on which the driver has no assigned tours, **When** the planner selects it, **Then** the map shows only the warehouse marker, the workday figures read as zero-length durations, and the tour list shows an explicit "no tours assigned" message rather than an empty box.
4. **Given** the planner is viewing 2026-07-23, **When** they press the "next day" arrow, **Then** the page shows 2026-07-24's tours, map and workday figures without a full page reload, and pressing the "previous day" arrow returns to 2026-07-23.
5. **Given** the planner is viewing any date, **When** they type or pick a different date directly in the date field, **Then** the page shows that date's data.

---

### User Story 2 - Inspect one tour of the day (Priority: P2)

From the day's tour list the planner clicks a tour. That tour becomes the focus: it is highlighted on the map in the primary colour along with the dotted drives arriving at and leaving from it, its stops appear on the map as numbered markers, and the list row unfolds to reveal each stop with its running index, its coordinate and its stop duration — the same numbers shown on the map markers.

**Why this priority**: Turning the day overview into a per-tour drill-down is what lets a planner diagnose a problem day. It depends on Story 1 existing but adds a distinct, separately testable capability.

**Independent Test**: With a driver day of at least two tours loaded, click each tour in turn and verify the map highlight moves, the numbered stop markers match the unfolded stop indexes, and clicking the selected tour again clears the selection.

**Acceptance Scenarios**:

1. **Given** a day with three tours, **When** the planner clicks the second tour, **Then** the second tour's path and the dotted drives immediately before and after it are drawn in the primary colour, the other tours and their connecting drives are drawn de-emphasised, and numbered stop markers appear for the second tour only.
2. **Given** a tour is selected, **When** the planner looks at the list row, **Then** it is unfolded showing one entry per stop with index 1..N in running order, the stop's coordinate, and the stop's duration, and index N on the list matches the marker labelled N on the map.
3. **Given** a tour is selected, **When** the planner clicks that same tour again, **Then** the selection clears, the map returns to the all-neutral day view, and the stop list folds away.
4. **Given** a day with several tours, **When** the planner clicks rapidly through different tours, **Then** the map and unfolded stop list always end on the last tour clicked, with no stale highlight, no duplicated markers, and no lost interaction.
5. **Given** a tour row, **When** the planner hovers it, **Then** it is highlighted in the secondary colour; **when** it is selected, **then** it is highlighted in the primary colour — matching the driver list's presentation on the tour pages.

---

### User Story 3 - Correct a driver's details (Priority: P3)

The planner changes the driver's name, picture, the set of delivery modes the driver can run, or the driver's assigned warehouse. The "Update" button next to those fields is disabled until something actually changes, then becomes available; pressing it saves the changes and the page reflects the saved state.

**Why this priority**: This is the "management" half of the page and the only way to fix driver data in the product today, but the day view is usable without it.

**Independent Test**: Open a driver's page, confirm the Update button starts disabled, change each field in turn and confirm the button enables, save, reload the page and confirm the new values persisted.

**Acceptance Scenarios**:

1. **Given** a freshly loaded driver page, **When** the planner has changed nothing, **Then** the driver-info Update button is visibly disabled and cannot be activated.
2. **Given** a loaded driver page, **When** the planner edits the name, replaces the picture, toggles a delivery mode, or picks a different warehouse, **Then** the Update button becomes available.
3. **Given** pending edits, **When** the planner presses Update, **Then** the changes are saved, the button returns to its disabled state, and a confirmation of the save is shown.
4. **Given** pending edits, **When** the planner reverts every field back to its original value by hand, **Then** the Update button returns to its disabled state.
5. **Given** the planner tries to save an empty name or a driver with no delivery mode selected, **When** they press Update, **Then** the save is rejected with a message naming the offending field and the previously saved values remain intact.
6. **Given** the save fails (server or network), **When** the failure returns, **Then** an error is shown, the planner's edits are still on screen, and the Update button stays available so the save can be retried.

---

### User Story 4 - Reorder the day's tours (Priority: P4)

The planner drags a tour by its left-hand handle to a new position in the day's list. The order shown updates immediately, and the "Tour order" Update button in the day bar — disabled until then — becomes available. Pressing it saves the new running order for that day.

**Why this priority**: Re-sequencing a day is a real planning need, but the day must first be viewable (Story 1) and it is the least frequent of the page's actions.

**Independent Test**: Load a day with at least three tours, drag the last tour to first, confirm the list order, map markers and Update button state, save, reload and confirm the new order persisted.

**Acceptance Scenarios**:

1. **Given** a day with three tours and no changes made, **When** the page loads, **Then** the "Tour order" Update button is disabled.
2. **Given** a day with three tours, **When** the planner drags the third tour to the first position, **Then** the list shows the new order, the map's tour-start markers relabel so the new first tour is "T1", and the "Tour order" Update button becomes available.
3. **Given** a pending reorder, **When** the planner presses the "Tour order" Update button, **Then** the new order is saved for that driver and date, the button returns to disabled, and reloading the page shows the saved order.
4. **Given** a pending reorder, **When** the planner changes the selected day, **Then** they are warned that the unsaved order will be lost and can cancel or continue.
5. **Given** a pending reorder, **When** the save fails, **Then** an error is shown, the reordered list stays on screen and the button remains available for retry.

---

### User Story 5 - Fix a tour's contents and come back (Priority: P5)

From a tour row the planner presses "Edit", lands on the existing tour-edit screen, changes the tour, and once the edit is confirmed is returned to the driver management page for the same driver and the same day, with the tour's new figures reflected.

**Why this priority**: It reuses an existing capability and only adds the navigation round-trip, so it delivers the least new behaviour — but it closes the loop between diagnosing a day and fixing it.

**Independent Test**: From a driver day, press Edit on a tour, confirm the edit, and verify the browser lands back on that driver's page on the same date with updated figures.

**Acceptance Scenarios**:

1. **Given** a tour row on the driver page, **When** the planner presses its Edit button, **Then** the existing tour-edit screen opens loaded with that tour, carrying the originating driver and date.
2. **Given** the planner arrived at the tour-edit screen from a driver page, **When** the re-optimization succeeds and records the tour, **Then** they are returned automatically to that driver's management page on the date they came from, and the edited tour's durations in the list and its path on the map reflect the edit.
3. **Given** the planner arrived from a driver page, **When** they press the back/cancel option, **Then** they are returned to the same driver page and date with nothing changed.
4. **Given** the planner arrived from a driver page, **When** the re-optimization fails or yields an unmeasurable (forced) outcome, **Then** they remain on the edit screen with the failure surfaced there and are not auto-returned.

---

### Edge Cases

- **Slow or unresponsive backend**: every duration figure, the tour list, and the map's paths show a placeholder or a spinner while their data is in flight; no figure ever appears as a real value before its data arrives.
- **External routing service unavailable**: paths that cannot be traced fall back to straight lines between the known points, and any duration that could not be measured is shown as unavailable — never as a zero that could be mistaken for "no travel time".
- **Partially unknown day**: when some connection times are unmeasurable, the workday total is presented as a lower bound (an "at least" reading) with a visible warning marker, rather than silently under-reporting.
- **Unknown driver**: opening the page for a driver id that does not exist yields a not-found response rather than an empty page.
- **Rapid day-flipping**: pressing the day arrows repeatedly always settles on the data for the last date selected; responses that arrive out of order never overwrite a newer day's data.
- **Rapid tour selection**: clicking through tours faster than data returns never leaves the map highlighting a tour other than the currently selected one.
- **Leaving with unsaved work**: navigating away, changing day, or pressing Edit on a tour while driver-info edits or a pending reorder exist warns the planner first.
- **Concurrent change**: if a tour shown in the list has been reassigned or deleted by someone else, saving the order reports the conflict and refreshes the day rather than writing a stale order.
- **A day of one tour**: the drag handles are inert (nothing to reorder) and the "Tour order" Update button stays disabled.
- **A tour with no measurable drive time** (for example a manually forced tour): its driven duration reads as manually entered or unavailable and it still contributes its stop time to the day's totals.
- **Very long tour lists**: the tour list scrolls on its own so the driver-info block and the day bar stay visible.
- **Narrow screens**: on a phone the header block, day bar, map and tour list stack and remain fully usable, including selecting a tour and reordering.

## Requirements *(mandatory)*

### Functional Requirements

#### Page & access

- **FR-001**: The system MUST serve a driver management page at `/driver/{id}` for the driver with that identifier, available only to authenticated, verified users.
- **FR-002**: The system MUST respond with a not-found result when the identifier matches no driver.
- **FR-003**: The page MUST work for a date supplied with the request and MUST default to the current date when none is supplied, so a given day is directly linkable.

#### Driver identity block

- **FR-004**: The page MUST display, at the top, the driver's picture (or a neutral placeholder when the driver has none), name, the delivery modes the driver can run, and the driver's assigned warehouse.
- **FR-005**: Users MUST be able to edit the driver's name, picture, set of delivery modes, and assigned warehouse from this block.
- **FR-006**: The block MUST provide an "Update" action, placed to the right of the fields, that is disabled while the on-screen values match the saved values and becomes available as soon as any of them differs.
- **FR-007**: The system MUST reject a save with an empty name or with no delivery mode selected, naming the offending field, and MUST leave the stored values unchanged in that case.
- **FR-007a**: Whenever a driver-detail save changes the assigned warehouse, the system MUST warn the user before saving that the change may affect the driver's existing assignments, and MUST let the user cancel or continue. The warning is a fixed advisory tied to the warehouse changing; it does not enumerate specific affected dates.
- **FR-007b**: When the user continues past that warning, the save MUST proceed and existing assignments MUST be left in place (not deleted or reassigned); each affected day MUST recompute from the driver's new details when it is next viewed, showing any now-unmeasurable figure as unavailable rather than as a wrong value.
- **FR-008**: On a successful save the system MUST persist the changes, confirm the save to the user, and return the Update action to its disabled state.
- **FR-009**: On a failed save the system MUST show an error, preserve the user's on-screen edits, and keep the Update action available for retry.

#### Day bar

- **FR-010**: The page MUST provide a day selector consisting of a previous-day arrow, an editable date field in the centre, and a next-day arrow; changing the day MUST reload that day's tours, map and figures without a full page reload.
- **FR-011**: The day bar MUST show, on its left, the day's Total workday, Driven time, Stop time and Break time as durations.
- **FR-012**: Driven time MUST cover the driving inside the day's tours plus the drives to, between and from the warehouse; Stop time MUST be the sum of the day's stop durations; Break time MUST be the legally required rest time for the day; Total workday MUST be the sum of driven, stop and break time.
- **FR-013**: Any of these figures that cannot be determined MUST be presented as unavailable, and a total containing an undetermined part MUST be marked as a lower bound with a visible warning rather than shown as a plain total.
- **FR-014**: The day bar MUST provide, on its right, a "Tour order" Update action carrying a label above it, aligned with the other labelled figures the same way labelled figures are aligned on the tour pages.

#### Map

- **FR-015**: The map MUST behave the same as the tour-result map (pan, zoom, fitting the shown content, road-accurate paths, the same base map presentation), minus any projected/candidate tour treatment — every tour shown is already assigned.
- **FR-016**: The map MUST draw each of the day's assigned tours as a solid line in the neutral route colour, and the drives from the warehouse to the first tour, between consecutive tours, and from the last tour back to the warehouse as dotted lines in that same neutral colour.
- **FR-017**: The map MUST show a warehouse marker at the driver's assigned warehouse position.
- **FR-018**: The map MUST show, at the point where the driver enters each tour, a marker labelled "T" followed by that tour's position in the day's order ("T1", "T2", "T3", …).
- **FR-019**: When a tour is selected, the map MUST draw that tour and the dotted drives arriving at and departing from it in the primary colour, de-emphasise the rest of the day, and show that tour's stops as numbered markers matching the running order of its stops.
- **FR-020**: When no tour is selected the map MUST show the whole day in the neutral treatment with no stop markers.
- **FR-021**: When a path cannot be traced on real roads, the map MUST fall back to a straight line between the known endpoints rather than omitting the path.

#### Tour list

- **FR-022**: Below the map the page MUST list the day's assigned tours in running order, each showing its total duration, driven duration and stop duration.
- **FR-023**: Clicking a tour row MUST select it; clicking the selected row again MUST clear the selection. At most one tour may be selected.
- **FR-024**: Tour rows MUST use the same presentation as the tour pages' driver list: secondary-colour highlight on hover, primary-colour highlight when selected.
- **FR-025**: The selected tour's row MUST unfold to list its stops, each showing its index in running order starting at 1, its coordinate, and its stop duration; these indexes MUST be the numbers used by the map's stop markers.
- **FR-026**: Each tour row MUST offer an "Edit" action that opens the existing tour-edit screen for that tour, carrying the originating driver and date so the screen can return there.
- **FR-027**: When an edit started from this page re-optimizes and successfully records the tour (the edit screen's save/Optimize action), the user MUST be returned automatically to this driver's page on the date they left from, with the edited tour's figures and path reflecting the edit.
- **FR-027a**: The edit screen reached from this page MUST offer a back/cancel option that returns the user to this driver's page and date without saving any change.
- **FR-027b**: When the re-optimization fails (including a forced/unmeasurable outcome), the user MUST remain on the edit screen so the problem can be handled, and MUST NOT be auto-returned; the failure MUST be surfaced there.
- **FR-028**: The tour list MUST scroll independently of the driver-info block and the day bar, which remain visible while the list is scrolled.
- **FR-029**: When the driver has no tours on the selected date, the list MUST show an explicit empty-state message.

#### Reordering

- **FR-030**: Each tour row MUST expose a drag handle on its far left by which the row can be moved to another position in the list.
- **FR-031**: Dragging a row MUST immediately update the displayed order, the "T" marker numbering on the map, and enable the day bar's "Tour order" Update action.
- **FR-032**: Pressing the "Tour order" Update action MUST persist the new running order for that driver and date and return the action to its disabled state.
- **FR-032a**: On saving a new order the system MUST recompute the day's shape for the new sequence — re-selecting the entry and exit point of each tour and re-measuring the drives from the warehouse to the first tour, between consecutive tours, and from the last tour back to the warehouse (the same entry/exit and connection logic used at assignment) — and MUST store the recomputed entry/exit points so the day's map and workday figures reflect the saved order.
- **FR-032b**: Because selecting each tour's optimal entry/exit needs the connection travel times, a normal order save MUST be blocked when any required connection cannot be routed: the system MUST surface a clear failure and MUST NOT persist a partially-recomputed order.
- **FR-032c**: On such a failure the system MUST offer a "force save" option that persists the new running order using a best-effort entry/exit selection (the routing-independent fallback the app already uses), storing the order without full re-optimization so a degraded routing service never leaves the day un-reorderable — mirroring the manual fallback of feature 024. A force-saved day MUST show any unmeasurable figure as unavailable rather than as a wrong value.
- **FR-033**: A failed order save MUST show an error, keep the reordered list on screen, and keep the action available for retry.
- **FR-034**: Saving an order that no longer matches the stored day (a tour was reassigned or removed elsewhere) MUST be reported as a conflict and MUST refresh the day instead of writing the stale order.
- **FR-035**: Reordering MUST NOT change any tour's stops, its own contents, or which driver it belongs to — only the day's running order and, as a consequence of FR-032a, each tour's recorded entry/exit points for that day.

#### Day mode invariant

- **FR-045**: A driver's day MUST be single-mode: every tour assigned to a driver on one date MUST share one delivery mode. That single mode is the day's mode — used to route the connecting drives (warehouse→tour, tour→tour, tour→warehouse) and the reorder recompute, and derived from the day's tours (which are guaranteed to agree).
- **FR-046**: The existing available-drivers request MUST enforce this invariant: for a candidate tour of mode M on date D, a driver who already has a tour of a mode other than M assigned on D MUST NOT be offered (their day is already committed to a different mode). A driver with no assignment on D, or with same-mode assignments on D, is unaffected. This makes the request date-aware for mode purposes; it MUST NOT change any other facet of the available-drivers behaviour.

#### Loading, failure and reactivity

- **FR-036**: Every value sourced from the backend MUST show a fallback placeholder until its data arrives and MUST show a fallback (never a fabricated value) when the data cannot be retrieved.
- **FR-037**: Lists and other potentially slow content that have no meaningful placeholder MUST show a loading indicator while awaiting data.
- **FR-038**: Failure of an external routing service MUST degrade the page (straight-line paths, unavailable durations) without preventing the rest of the page from working.
- **FR-039**: Responses that arrive after the user has moved on — changed day, changed or cleared tour selection — MUST be discarded, so the display always reflects the latest user action.
- **FR-040**: Repeated rapid interaction (day arrows, tour selection, drag) MUST leave the page in the state implied by the last action, with no stuck spinners, duplicated map layers, or lost selections.
- **FR-041**: Navigating away, changing the selected day, or opening a tour edit while unsaved driver edits or an unsaved order exist MUST warn the user first and allow them to cancel.

#### Presentation

- **FR-042**: The whole page MUST be usable on mobile as it is on desktop: content stacks rather than overflowing horizontally, controls stay reachable, and tour selection and reordering remain operable by touch.
- **FR-043**: All colours used by the page MUST come from the project's role-named palette (primary, secondary, background, text, accent, route-neutral), with no one-off colour values.
- **FR-044**: Durations MUST be formatted the same way as on the tour pages.

### Key Entities *(include if feature involves data)*

- **Driver**: the person being managed — picture, name, the delivery modes they can run, and the warehouse they start and end their days at.
- **Warehouse**: a named location the driver departs from and returns to; anchors the day's first and last connecting drives and the map's warehouse marker.
- **Delivery mode**: a way of travelling (walking, driving, trucking) that a driver may be qualified for and that a tour is planned for.
- **Tour**: an ordered set of stops the driver runs in one go, with its own driving duration and total distance, and a loop/one-way shape.
- **Stop**: one delivery point in a tour — its position in the tour's running order, its coordinate, and how long the driver spends there.
- **Assignment**: the link between a driver, a tour and a date, carrying the tour's position in that day's running order and the points at which the driver enters and leaves the tour.
- **Workday summary**: the derived totals for one driver on one date — driven time, stop time, required break time, and total workday, each possibly undetermined.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A planner can open a named driver, land on a chosen day, and read that day's total workday, driven time, stop time and break time without any further navigation.
- **SC-002**: The page presents its structure (identity block, day bar, map frame, list frame) with placeholders within 1 second on a normal connection, and no figure is ever shown as a real value before its data has arrived.
- **SC-003**: 100% of backend-sourced values on the page have a defined fallback presentation for both "loading" and "unavailable", verified field by field.
- **SC-004**: Selecting a different tour updates the map highlight and the unfolded stop list within 300 ms for data already loaded, and clicking through 10 tours in rapid succession always ends on the last one clicked.
- **SC-005**: With the external routing service fully unavailable, the page still renders every tour, marker, list row and stop, with straight-line paths and explicitly unavailable durations, and remains fully interactive.
- **SC-006**: A planner can reorder a three-tour day and save the new order in under 30 seconds, and the order survives a page reload.
- **SC-007**: A planner can correct a driver's name, modes and warehouse and save in under 60 seconds, with the save action never available while nothing has changed.
- **SC-008**: The page renders without horizontal overflow and with every control reachable at viewport widths from 320 px to 2560 px.
- **SC-009**: Editing a tour from the driver page returns the planner to the same driver and date in 100% of confirmed and abandoned edits.
- **SC-010**: No user-visible failure on the page is silent: every failed load or save produces a message identifying what failed and what the user can do.

## Assumptions

- The page is reachable by any authenticated, verified user; the product has no per-user role system today, and "administrator" is read as the current signed-in user acting in a planning capacity. Drivers are shared, not scoped per user or team.
- The driver's weekday schedule (which weekdays they work) is not editable from this page — the description lists only picture, name, modes and warehouse.
- Editing the driver's picture means uploading a replacement image; drivers keep the "no picture" placeholder used elsewhere in the product when none is set.
- Warehouse selection is a choice among existing warehouses; creating or editing warehouses is out of scope.
- "Driven time", "stop time", "break time" and "total workday" follow the definitions already used for the projected-workday figure on the tour pages, including the existing mandatory-break rules, applied to the day's actual assignments rather than to a candidate tour.
- The map reuses the tour-result map's behaviour and visual language; only the projected/candidate-tour emphasis is dropped.
- A tour appears on exactly one driver's day; assignment remains one driver per tour.
- Saving a reordered day recomputes and re-stores each tour's entry/exit points and the connecting drives for the new order (Clarifications 2026-07-23); reordering a single-tour day is a no-op. A driver-detail edit that invalidates existing assignments is allowed after an explicit warning and never rewrites those assignments.
- Selection state, the pending driver edits and the pending order are per-visit and are not preserved across a reload.
- The existing tour-optimize, geometry and assignment endpoints keep their current behaviour. The one exception is the available-drivers request, which this feature makes date-aware for mode only (FR-046) so a driver's day stays single-mode (FR-045); how tours are optimized is unchanged.
- A driver's day being single-mode (FR-045) is guaranteed going forward by the FR-046 filter. Any pre-existing mixed-mode day in the data (assigned before this filter) is out of scope; the driver page derives the day mode from the tours and, if they disagreed, would use the earliest tour's mode without special handling.

## Clarifications

### Session 2026-07-23

- Q: When a new tour order is saved, does the day's shape recompute or is only the running order stored? → A: Recompute — re-select each tour's entry/exit points and re-measure the warehouse/inter-tour connections for the new order, and store the recomputed points (FR-032a, FR-035).
- Q: How should a driver-detail edit that invalidates existing assignments behave? → A: Allow with a warning; the warning is a fixed advisory shown whenever the warehouse changes (no per-date enumeration), and existing assignments are left in place and recomputed when next viewed (FR-007a, FR-007b).
- Q: On the tour-edit screen reached from this page, what returns the planner here? → A: Auto-return on a successful re-optimize (the Optimize/save action); a back/cancel option returns without saving; on failure stay on the edit screen (FR-027, FR-027a, FR-027b).

### Session 2026-07-23 (planning)

- Q: How is drag-reorder input built (no DnD library present)? → A: Add `@dnd-kit/sortable` — accessible, touch-capable, fits the drag-handle + mobile requirement.
- Q: What happens to a reorder save when the routing API cannot measure a connection? → A: Block the normal (optimal-recompute) save and offer a "force save" that persists the order with a best-effort entry/exit fallback (FR-032b, FR-032c).

### Session 2026-07-23 (analysis)

- Q: A day can contain tours of different modes (each tour stores its own mode; the assignment stores none) — which mode routes the connecting drives and the reorder recompute? → A: A day is single-mode. The first tour assigned to a driver on a date fixes that day's mode; the available-drivers request then offers only that mode for that driver+date, so no mixed-mode day can form. The day's mode is derived from its tours (FR-045, FR-046).
