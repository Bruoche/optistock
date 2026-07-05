# Feature Specification: Mobile Scrollable Content Panel

**Feature Branch**: `022-mobile-panel-scroll`

**Created**: 2026-07-05

**Status**: Draft

**Input**: User description: "This seems to be working, however now I can't seem to scroll through to the drivers as the bar is taking the whole div and is non-scrollable, pushing the scrollable list down. First, on mobile (only on mobile) we should remove the padding around the orange bar so we don't see small black borders all around the box, and we should also be able to scroll this box up. The box shouldn't appear above the boundary however, it should disappear \"behind the map\" (as in it should be visible through the map). Make sure this however doesn't impact the desktop visual."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Reach the driver list on a phone by scrolling the panel (Priority: P1)

On a phone, the tour screen shows a map on top and, below it, a content panel: the orange bar (workday figures + actions) followed by the available-driver list. Because the orange bar now wraps onto several rows on a narrow screen, it fills the whole panel and pushes the driver list off the bottom, and the bar itself can't be scrolled — so the planner can't reach the drivers. This feature makes the panel scroll as a whole: the planner scrolls the orange bar up and out of the way, revealing and reaching the full driver list. The bar, as it scrolls up, disappears beneath the map (it is hidden by the map region, never drawn on top of it).

**Why this priority**: Right now, on a phone, the drivers are unreachable — the core assignment flow is blocked on mobile. Restoring access to the list is the whole point.

**Independent Test**: On a phone-width viewport in the result view, scroll the content panel up and confirm the orange bar moves out of view beneath the map and every driver in the list becomes reachable and selectable.

**Acceptance Scenarios**:

1. **Given** the result view on a phone where the orange bar fills the panel, **When** the planner scrolls the panel up, **Then** the bar scrolls up and the driver list below it comes into view and can be scrolled through to the last driver.
2. **Given** the planner scrolls the orange bar upward, **When** it reaches the top of the panel, **Then** it disappears beneath the map region and is never drawn above the map/panel boundary.
3. **Given** a phone where the bar + list already fit without overflow, **When** the view loads, **Then** no scrolling is forced and nothing is clipped.
4. **Given** a driver is reached by scrolling, **When** the planner taps it, **Then** it selects normally (the scroll does not break selection).

---

### User Story 2 - Edge-to-edge orange bar on mobile (Priority: P2)

On a phone, the orange bar spans the full width of the panel with no surrounding gap, so there is no thin dark border of background showing around it. On desktop the bar keeps its current inset/padding.

**Why this priority**: Cosmetic polish that makes the mobile view look intentional rather than boxed-in; valuable but secondary to actually reaching the drivers (US1).

**Independent Test**: On a phone-width viewport, confirm the orange bar touches the left and right edges of the panel with no visible background border around it; on a wide viewport, confirm the inset/padding is unchanged.

**Acceptance Scenarios**:

1. **Given** the tour screen on a phone, **When** it renders, **Then** the orange bar reaches the side edges of the panel with no dark background border framing it.
2. **Given** the tour screen on desktop, **When** it renders, **Then** the bar keeps its current surrounding padding exactly as before.

---

### Edge Cases

- **Bar + list fit without overflow (short list)**: no forced scroll; the bar stays in place and nothing is clipped.
- **Rotation portrait↔landscape**: the panel scrolls when its content overflows the available height and stops when it fits, at whatever the current width is.
- **Editing view (bar + stop list)**: the same panel structure appears while building a tour (orange control bar above the stop list); it behaves the same on mobile so the stop list stays reachable.
- **Desktop / wide screens**: unchanged — the bar stays fixed at the top of the panel with the list scrolling within its own area, and the surrounding padding remains.
- **Scrolled-away bar must not cover the map**: at no scroll position does any part of the bar render on top of the map.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: On phone-width screens, the tour screen's bottom content panel MUST scroll as a whole so the orange bar can move out of view and the list beneath it becomes fully reachable.
- **FR-002**: When the panel scrolls, the orange bar MUST be hidden beneath the map region as it moves up — it MUST NOT render above the map/panel boundary or overlap the map at any scroll position.
- **FR-003**: On phone-width screens, the orange bar MUST span the full width of the panel with no surrounding padding or background border ("black border") framing it.
- **FR-004**: On desktop/wide screens, the panel MUST keep its current behavior and appearance unchanged: the bar fixed at the top, the list scrolling within its own area, and the surrounding padding/inset retained.
- **FR-005**: The mobile panel-scroll behavior MUST apply in both content states — the result view (orange bar + driver list) and the editing view (orange control bar + stop list) — so the list is always reachable on a phone.
- **FR-006**: Every control in the bar and every item in the list MUST remain reachable and operable on a phone after the change (scrolling MUST NOT break selection or interaction).

### Key Entities

- Not applicable — this feature changes presentation/layout only; it introduces no new data.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On a 360px-wide viewport in the result view, a user can scroll to and select any driver in the list, including the last one.
- **SC-002**: On phone-width viewports, the orange bar touches both side edges of the panel with no visible background border around it.
- **SC-003**: At every scroll position on a phone, no part of the orange bar is visible above the map's bottom edge.
- **SC-004**: On wide (desktop) viewports, the panel's layout, padding, and scroll behavior are unchanged from before the feature.
- **SC-005**: A first-time mobile user can complete the core flow (optimize → scroll → reach and assign a driver) on a phone without the bar blocking the list.

## Assumptions

- "Mobile" means small/phone viewports (below the app's standard mobile breakpoint); the behavior is viewport-driven, not a user-toggled mode.
- The map region stays fixed at the top of the screen; only the bottom content panel scrolls on mobile, and the map visually occludes the bar as it scrolls up (the phrase "behind / through the map" is read as "hidden beneath the map region", not literally translucent).
- The change is scoped to the tour optimization screen's bottom content panel; other screens are unaffected.
- Desktop retains today's layout exactly: fixed bar, list scrolling within its own area, and the current padding around the bar.
- Removing the mobile padding refers to the panel's outer padding that currently frames the orange bar with a strip of page background; the bar's own internal padding (around its controls) is unchanged.
- Dropping that outer padding makes the whole panel full-bleed on mobile — the driver / stop-list rows also reach the screen edges, not just the orange bar. This is intended (a standard mobile full-width list look); the list keeps no side padding on phones.
- The list's own internal scrolling still works; on mobile it becomes part of the single panel scroll so the whole thing reads as one scroll surface.
