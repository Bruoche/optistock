# Feature Specification: Warehouse & Origin Map Markers

**Feature Branch**: `018-warehouse-origin-markers`

**Created**: 2026-07-04

**Status**: Draft

**Input**: User description: "We will do small tweaks to the presentation menu. We will add a round point on the map where the driver's warehouse is when we click on them (like the numbered circles that show the position of the stops) It will be the same size as these stop markers, but will have a building icon and will be black at 50% opacity. Likewise, if the precedent stop before the incoming tour is not the warehouse (if there are already assigned tours for the day) we will also add a \"0\" black marker at 50% opacity, so we also know where the driver is coming from when tours are assigned clearly without having to look at the dotted lines."

## Context

In the presentation view, clicking a driver in the available-driver list selects them and draws their projected workday on the map: the candidate tour in the primary colour, the neutral already-assigned tours, and the dashed connection drives around them (features 013–015). The tour's own stops render as numbered circle markers (1, 2, …). But two important places in that day carry no point marker of their own: the driver's **warehouse** (where the day begins and ends) and, when the driver already has earlier tours that day, the **place the driver is coming from** into the candidate tour (the end of their last prior tour). Today a manager can only infer those points by tracing the dashed connection lines.

This feature adds two circle markers, matching the existing numbered stop markers in size, shown while a driver is selected:

- A **warehouse marker** at the selected driver's warehouse — a building icon, black at 50% opacity.
- A **"0" origin marker** at the point the driver comes from into the candidate tour — shown only when that point is **not** the warehouse (i.e. the driver already has an assigned tour that day), black at 50% opacity.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See where the driver's warehouse is (Priority: P1)

A delivery manager selects a driver in the presentation view and immediately sees a marker on the map at that driver's warehouse, so they can tell at a glance where the day starts and ends without following the dashed lines.

**Why this priority**: The warehouse anchor is the always-present reference point for every driver's workday; it applies to every driver regardless of prior tours, so it delivers value on its own.

**Independent Test**: Select any driver in the presentation view and confirm a building-icon marker appears at that driver's warehouse, the same size as the numbered stop markers, in black at 50% opacity.

**Acceptance Scenarios**:

1. **Given** the presentation view with the driver list, **When** the manager selects a driver, **Then** a round marker with a building icon appears on the map at that driver's warehouse.
2. **Given** a warehouse marker is shown, **When** the manager compares it to the numbered stop markers, **Then** it is the same size and shape (a circle), rendered black at 50% opacity.
3. **Given** a driver is selected, **When** the manager deselects the driver (or selects another), **Then** the warehouse marker is removed (or moves to the newly selected driver's warehouse).

---

### User Story 2 - See where the driver is coming from when tours are already assigned (Priority: P2)

When the selected driver already has an earlier tour that day, the manager sees a "0" marker at the point the driver drives in from (the end of the last prior tour), so the origin of the candidate tour is obvious without reading the dashed connection line.

**Why this priority**: This only applies to drivers with prior assigned tours, so it is a narrower case than the always-present warehouse marker, but it removes the same "read the dotted line" guesswork for the multi-tour day.

**Independent Test**: Select a driver who already has an assigned tour that day and confirm a "0" marker appears at the end of that prior tour (the incoming origin), black at 50% opacity, same size as the stop markers; select a driver with no prior tours and confirm no "0" marker appears.

**Acceptance Scenarios**:

1. **Given** a selected driver with at least one already-assigned tour that day, **When** the manager looks at the map, **Then** a round "0" marker appears at the point the driver comes from into the candidate tour (the end of the last prior tour), black at 50% opacity.
2. **Given** a selected driver with no already-assigned tour that day (the driver comes straight from the warehouse), **When** the manager looks at the map, **Then** no "0" marker is shown — only the warehouse marker.
3. **Given** the "0" marker is shown, **When** the manager compares it to the numbered stop markers, **Then** it is the same size and circle shape, labelled "0", continuing the stop numbering as the point before stop 1.

---

### Edge Cases

- **Incoming origin equals the warehouse**: when the driver has no prior tours, the origin is the warehouse itself; only the warehouse marker is shown and no "0" marker (they would otherwise overlap). This is the condition that gates the "0" marker.
- **No driver selected**: neither the warehouse marker nor the "0" marker is shown; they belong to the selected driver's preview, like the workday lines.
- **Warehouse marker overlapping a stop marker**: if a warehouse or origin sits at/near a numbered stop, both markers still render; the 50%-opacity styling keeps the numbered stop readable underneath.
- **Missing coordinate for the warehouse or origin**: if the location of the warehouse or the incoming origin is unknown, that marker is simply not drawn rather than placed at a wrong/default position.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: While a driver is selected in the presentation view, the map MUST show a marker at that driver's warehouse.
- **FR-002**: The warehouse marker MUST be a circle the same size and shape as the existing numbered stop markers, displaying a building icon, rendered black at 50% opacity.
- **FR-003**: While a driver is selected, the map MUST show a "0" marker at the point the driver comes from into the candidate tour (the end of the driver's last already-assigned tour that day) **only when** that point is not the warehouse.
- **FR-004**: The "0" marker MUST be a circle the same size and shape as the numbered stop markers, labelled "0", rendered black at 50% opacity.
- **FR-005**: When the driver has no already-assigned tour that day (the driver departs from the warehouse), the "0" marker MUST NOT be shown.
- **FR-006**: Both markers MUST appear only while a driver is selected and MUST update when the selected driver changes and be removed when no driver is selected.
- **FR-007**: The markers MUST NOT alter the existing numbered stop markers, the workday connection/tour lines, or the candidate-tour route rendering.
- **FR-008**: The two new markers MUST be visually distinguishable from the numbered stop markers (which are the primary colour) by their black 50%-opacity styling and, for the warehouse, its building icon.

### Key Entities *(include if feature involves data)*

- **Warehouse marker (per selected driver)**: a point at the selected driver's warehouse location, the fixed start/end of that driver's projected day.
- **Origin ("0") marker (per selected driver)**: a point at the place the selected driver drives in from for the candidate tour — the end of the last prior tour — shown only when it differs from the warehouse.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For every selected driver, the manager sees a warehouse marker at that driver's warehouse — 100% of selected drivers.
- **SC-002**: For every selected driver with at least one prior assigned tour that day, a "0" origin marker is shown at the incoming origin; for drivers with no prior tour, it is absent — correct in 100% of cases.
- **SC-003**: The new markers match the numbered stop markers in size and shape and are rendered black at 50% opacity, distinguishable from the primary-colour stop markers at a glance.
- **SC-004**: A manager can identify a driver's warehouse and (when applicable) incoming origin without tracing the dashed connection lines.

## Assumptions

- "Presentation menu / view" is the result view where the available-driver list is shown and selecting a driver previews their projected workday on the map (features 014–016).
- The markers are tied to the currently selected driver's preview, appearing/updating/clearing alongside the existing workday lines.
- The warehouse location and the incoming-origin location for the selected driver are available to the map (directly or derivable from the driver's already-drawn workday pieces); this feature surfaces them as markers and does not introduce new routing.
- "Same size as these stop markers" and "black at 50% opacity" reuse the existing stop-marker sizing and a neutral/black colour at half opacity rather than introducing a new palette entry.
- The "0" label continues the stop numbering (stops are 1…N; the origin is the point before stop 1), consistent with how the numbered stop markers read.
- This is a display-only, frontend addition to the presentation view; driver ordering, selection behaviour, assignment, road-time figures, and the workday lines are unchanged.
