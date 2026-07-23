# Feature Specification: Driver Page Map & Day-Bar Fixes

**Feature Branch**: `025-driver-management-page` (refinement — no new branch)

**Created**: 2026-07-23

**Status**: Draft

**Input**: User description: "Fixes to scope over the newly made driver-management page. Markers are well placed, but on first load no polylines show — the map should instantly show the tours (straight lines first, then polylines once received) rather than only after a tour is clicked. When a tour is clicked the straight lines remain on top of the polylines (both showing) — the straight line should be replaced by the detailed line, not shown alongside it. Regular (neutral, unselected) tour lines should be slightly transparent (about 75% opacity), less dimmed than when a tour is selected. The top orange bar should sit under the map and above the tour list so the 'Tour order' Update button's relationship to the listed tours is clear. The current weekday (Monday, Tuesday, …) should be a title label above the date field, aligned with the other labels in the bar, not hovering above the prev/next arrows, with every field aligned."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See the day's tours the moment the page opens (Priority: P1)

A planner opens a driver's day. The map immediately draws every assigned tour and every connecting drive — first as straight lines (a fast fallback), then each straight line is replaced by its road-accurate polyline as the tracing arrives — without the planner having to click anything.

**Why this priority**: The map is the centre of the page; today it appears empty (no lines) until a tour is clicked, so the day looks broken on arrival. Showing the tours on load is the core of this fix.

**Independent Test**: Open a driver-day with at least one assigned tour and take no action; verify tour and connection lines are visible right away (straight at first) and become road-accurate shortly after, with no interaction.

**Acceptance Scenarios**:

1. **Given** a driver-day with assigned tours, **When** the page finishes loading, **Then** every tour and connecting drive is drawn on the map immediately as a straight-line fallback, with no click required.
2. **Given** the straight-line fallback is showing, **When** the road-tracing for a segment arrives, **Then** that segment's straight line is replaced in place by its road-accurate polyline.
3. **Given** the routing service cannot trace a segment, **When** its tracing fails, **Then** that segment keeps its straight-line fallback (it is never left blank).
4. **Given** an empty day (no assigned tours), **When** the page loads, **Then** the map shows only the warehouse marker and no tour lines (unchanged).

---

### User Story 2 - One clean line per segment, correctly emphasised (Priority: P2)

Each tour and connection is drawn as exactly one line at any moment. Selecting a tour never leaves a leftover straight line on top of its polyline. With nothing selected, the neutral tour lines are lightly dimmed (about 75% opacity) so the map reads calmly; selecting a tour emphasises it and its bracketing drives at full strength while the rest of the day dims further.

**Why this priority**: Overlapping straight+polyline lines and an over-heavy neutral state make the map look wrong and cluttered. Depends on US1's on-load drawing being in place.

**Independent Test**: With a multi-tour day loaded, confirm each segment shows a single line; select a tour and confirm no duplicate straight line appears over its polyline; compare opacities across the no-selection and selected states.

**Acceptance Scenarios**:

1. **Given** any segment (tour or connection), **When** the map is rendered in any state, **Then** exactly one line is drawn for it — the straight fallback OR the polyline, never both at once.
2. **Given** a tour is selected, **When** its polyline is shown, **Then** no straight-line version of that same tour is drawn over or under it.
3. **Given** no tour is selected, **When** the day is drawn, **Then** the neutral tour lines render at about 75% opacity (visible but lightly dimmed), not fully opaque.
4. **Given** a tour is selected, **When** the emphasis is applied, **Then** the selected tour and the drives immediately arriving at and leaving it are drawn at full opacity, and every other tour and drive is dimmed more than the no-selection state (i.e. clearly less prominent than 75%).
5. **Given** a selected tour, **When** it is deselected, **Then** the map returns to the all-neutral 75% state with that tour drawn as a single neutral line (no residual straight line, no stuck highlight).

---

### User Story 3 - Day bar under the map with aligned labels (Priority: P3)

The bar carrying the workday figures, the day navigation, and the "Tour order" Update button sits directly between the map and the tour list, so it reads as belonging to the list it orders. Every field in the bar is aligned: a row of title labels on top and their values/controls beneath. The current weekday name appears as a title label above the date field, on the same line as the other labels, and the previous/next-day arrows sit on the value row beside the date field — the weekday no longer floats above the arrows.

**Why this priority**: A presentation/layout refinement; the bar already works, so this is the least urgent, but it clarifies the relationship between the Update action and the tours.

**Independent Test**: Load the page; confirm the bar is positioned below the map and above the tour list, that the weekday label is above the date field and horizontally in line with the other bar labels, and that the arrows and date field share the value row.

**Acceptance Scenarios**:

1. **Given** the driver-day page, **When** it is displayed, **Then** the bar appears below the map and above the tour list.
2. **Given** the bar, **When** it is displayed, **Then** the weekday name (e.g. "Monday") is shown as a label above the date field, aligned on the same row as the bar's other labels (Total workday, Driven, Stops, Break, Tour order).
3. **Given** the day-navigation group, **When** it is displayed, **Then** the previous-day arrow, the date field, and the next-day arrow sit together on the values row; the weekday label is above only the date field, not above the arrows.
4. **Given** the bar's contents, **When** they are displayed, **Then** all labels align along one row and all values/controls align along the row beneath, consistent with the label/value alignment used on the tour pages.

### Edge Cases

- A tour whose road-tracing is slow: it stays on its straight fallback until the polyline arrives, then swaps — never showing both.
- Rapidly selecting/deselecting tours: the map always ends in the correct single-line state for the final selection, with no accumulated or leftover lines.
- A day with a single tour: it still draws on load and shows at 75% neutral opacity when unselected.
- Narrow screens: the relocated bar still wraps its groups without horizontal overflow, and the label/value alignment holds per group.

## Requirements *(mandatory)*

### Functional Requirements

#### Map — on-load drawing

- **FR-001**: The map MUST draw every assigned tour and every connecting drive as soon as the day's data is available, with no user interaction required.
- **FR-002**: Each segment MUST first render as a straight-line fallback and then be replaced in place by its road-accurate polyline once the tracing for that segment arrives.
- **FR-003**: A segment whose road-tracing cannot be obtained MUST keep its straight-line fallback rather than disappearing.
- **FR-004**: On-load drawing MUST NOT depend on any tour being selected first.

#### Map — single line and opacity

- **FR-005**: For any tour or connection, the map MUST draw exactly one line at a time — the straight fallback or the polyline, never both simultaneously.
- **FR-006**: Selecting a tour MUST NOT produce a duplicate straight line drawn over or under its polyline.
- **FR-007**: When no tour is selected, the neutral tour lines MUST be rendered at approximately 75% opacity.
- **FR-008**: When a tour is selected, the selected tour and the drives immediately bracketing it MUST be rendered at full opacity, and all other tours and drives MUST be dimmed further than the no-selection state (clearly less prominent than 75%).
- **FR-009**: Deselecting a tour MUST return the whole day to the neutral 75%-opacity state, each segment drawn as a single line with no residual straight line or leftover highlight.
- **FR-010**: The dotted vs. solid distinction (connections dotted, tours solid) and the colour roles (neutral vs. primary) MUST be preserved through all of the above; only opacity and the single-line behaviour change.

#### Day bar — placement and alignment

- **FR-011**: The bar containing the workday figures, day navigation, and the "Tour order" Update button MUST be positioned below the map and above the tour list.
- **FR-012**: The current weekday name MUST be shown as a title label above the date field.
- **FR-013**: The weekday label MUST align on the same row as the bar's other title labels; the date field and the previous/next-day arrows MUST align on the values row beneath, with the weekday label positioned above only the date field (not above the arrows).
- **FR-014**: All of the bar's labels MUST align along one row and all of its values/controls along the row beneath, consistent with the label/value alignment used on the tour pages.
- **FR-015**: The relocation and alignment MUST NOT introduce horizontal overflow; the bar's groups MUST continue to wrap on narrow screens.

### Non-Functional / Scope

- **FR-016**: These changes are presentation-only for the driver-management page. The day-data, geometry, driver-update, and tour-order behaviours and their payloads MUST remain unchanged.
- **FR-017**: All colours MUST continue to come from the project's role-named palette; no off-palette values may be introduced.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On opening a driver-day with assigned tours, tour and connection lines are visible within 1 second with no interaction, and become road-accurate as tracing lands.
- **SC-002**: At no point — on load, on select, or on deselect — is any single segment drawn as two overlapping lines; 100% of segments show exactly one line.
- **SC-003**: With nothing selected, neutral tour lines are visibly dimmed to about 75% opacity; with a tour selected, its emphasis is at full opacity and the rest are dimmed below the neutral state.
- **SC-004**: The bar sits between the map and the tour list, and the weekday label lines up with the other bar labels while the arrows sit beside the date field on the value row — verified visually at desktop and mobile widths.
- **SC-005**: The page renders without horizontal overflow from 320 px to 2560 px after the relocation.
- **SC-006**: No change is observed in the driver-day, geometry, driver-update, or tour-order responses (existing behaviour and tests remain green).

## Assumptions

- The observed "no lines until a tour is clicked" behaviour and the "straight line staying on top of the polyline" are rendering issues on the driver page only; the day-data and geometry the page receives are correct (the endpoints work), so no backend change is needed.
- "About 75% opacity" is the target for the neutral, no-selection tour lines; the existing dim used for non-selected segments while a tour is selected (roughly 50%) is kept as the "dimmed further" state, and the selected/bracketing segments stay at full opacity.
- The weekday name shown is the one already derived for the selected date; no new date logic is introduced.
- Moving the bar changes only its position in the page's vertical stack (identity block, then map, then bar, then tour list); the bar's internal controls and their behaviour are unchanged apart from the label/alignment refinement.
- The selected tour's stops continue to show as numbered markers as they do today; only the duplicate *line* is removed.
