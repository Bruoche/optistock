# Feature Specification: New-Tour Confirmation & Presentation-Layer Mode Selector

**Feature Branch**: `016-tour-confirm-and-mode`

**Created**: 2026-07-04

**Status**: Draft

**Input**: User description: "We currently have a pop-up appearing when assigning drivers to a tour asking if we confirm we want to do so. I'd like to quickly add the same pop-up for when we make a new tour confirming we want to drop the current on-going tour. Also, I'd like to have other small edits to the presentation layer by also adding back the button to select driving mode (trucking, driving, walking) into the presentation layer as it also concerns drivers, so the user can switch and have the driver list reload according to what has been selected."

## Context

This feature makes two small edits to the **presentation view** — the result screen shown after a tour is optimized (feature 003's editing control bar, feature 006's available-driver list, feature 012's assignment confirmation, feature 014's "Assign Driver" button + workday preview).

1. **New-tour confirmation**: Today, starting a new tour from the result view discards the currently displayed ("on-going") tour immediately, with no confirmation — unlike assigning a driver, which already asks the planner to confirm. This feature adds the **same confirmation step** before dropping the current tour, reusing the established confirm-dialog pattern.

2. **Delivery-mode selector in the presentation view**: The trucking/driving/walking selector (feature 003) currently lives only in the **editing** control bar, so once a tour is displayed the planner can no longer change mode. Because the available-driver list is filtered by mode (feature 006 — different drivers qualify for different transport modes), the planner wants to switch mode **from the result view** and have the **driver list reload** for the newly selected mode without leaving the presentation view.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Confirm before dropping the current tour (Priority: P1)

A delivery planner viewing an optimized tour clicks "New tour" to start over. Before the current tour is discarded, a confirmation dialog appears asking them to confirm they want to drop the on-going tour. Confirming clears the tour and returns to the editing view; cancelling keeps the current tour on screen unchanged.

**Why this priority**: Discarding an optimized tour is a destructive, easy-to-mistrigger action (a single click next to "Assign Driver"). The assignment flow already guards its action with a confirmation; this closes the inconsistency and prevents accidental loss of an optimized tour.

**Independent Test**: From a displayed tour, click "New tour", confirm — the tour clears and the editing view returns. Repeat and cancel — the same tour stays on screen with no change.

**Acceptance Scenarios**:

1. **Given** an optimized tour is displayed, **when** the planner clicks "New tour", **then** a confirmation dialog appears asking them to confirm dropping the current tour, and the tour is **not** yet discarded.
2. **Given** the confirmation dialog is open, **when** the planner confirms, **then** the current tour is discarded and the editing view (stop list + control bar) returns.
3. **Given** the confirmation dialog is open, **when** the planner cancels or dismisses it, **then** the dialog closes and the displayed tour remains exactly as it was (same stops, mode, driver list, and any selected driver).

---

### User Story 2 - Switch delivery mode from the presentation view to reload the driver list (Priority: P1)

While viewing an optimized tour, a delivery planner changes the delivery mode (trucking / driving / walking) using a selector in the result view. The available-driver list reloads to show the drivers who qualify for the newly selected mode, with each driver's projected workday recomputed for that mode.

**Why this priority**: Which drivers can take a delivery depends on the transport mode, and today that choice is locked once a tour is displayed — forcing the planner back to editing (and re-optimizing) just to see who is available under a different mode. Letting them switch in place is the core convenience this feature delivers.

**Independent Test**: On a displayed tour, change the mode selector and confirm the driver list reloads for the newly selected mode (drivers qualifying for that mode, projected times computed for it); switch back and confirm the list matches the original mode again.

**Acceptance Scenarios**:

1. **Given** a tour displayed with its driver list shown for the mode it was optimized with, **when** the planner selects a different mode, **then** the driver list reloads showing the drivers available for the newly selected mode.
2. **Given** a driver was selected (their workday previewed on the map), **when** the planner switches mode, **then** the previewed selection clears and the list reflects the new mode (consistent with feature 014's rule that any driver-list reload clears the selection).
3. **Given** the planner switched to a mode with no qualifying drivers, **when** the list reloads, **then** the existing "no one available" state is shown for that mode.
4. **Given** the planner reopens the result view for a tour, **when** they first see the mode selector, **then** it shows the mode the tour was optimized with as the initial selection.

---

### Edge Cases

- **Assign Driver already guards its own action**: the new-tour confirmation applies only to the "New tour" action. Assigning a driver keeps its existing confirmation (feature 012) and is unaffected.
- **Cancelling the new-tour confirmation** must be a true no-op: no reset, no driver-list reload, no loss of the selected driver or previewed workday.
- **Switching mode does not re-optimize the displayed tour geometry**: the polyline and stop order on screen were computed for the mode the tour was optimized with and stay as-is. Changing the presentation-view mode reloads only the **driver list** (and a later-selected driver's workday preview) for the new mode (FR-009).
- **Mode switch while the driver list is still loading**: the latest selected mode wins; no stale list from a previously selected mode is shown (existing driver-fetch behavior).
- **Selecting the same mode already shown**: no-op; the list need not visibly reload.

## Requirements *(mandatory)*

### Functional Requirements

#### New-tour confirmation

- **FR-001**: When the planner triggers "New tour" from a displayed (on-going) tour, the system MUST present a confirmation dialog asking them to confirm dropping the current tour before anything is discarded.
- **FR-002**: The confirmation dialog MUST reuse the same interaction pattern as the existing driver-assignment confirmation (feature 012): a modal with a confirm action and a cancel/dismiss action, and the app's shared dialog styling.
- **FR-003**: Confirming MUST discard the current tour and return to the editing view (stop list + control bar), i.e. the same outcome as today's immediate reset.
- **FR-004**: Cancelling or dismissing the dialog MUST leave the displayed tour and all its current state (stops, selected mode, driver list, selected driver / previewed workday) unchanged.
- **FR-005**: The confirmation MUST apply only to the "New tour" action; assigning a driver retains its own separate confirmation and behavior.

#### Presentation-view mode selector

- **FR-006**: The system MUST present a delivery-mode selector (trucking / driving / walking) within the presentation (result) view, offering the same three modes as the editing-view selector (feature 003).
- **FR-007**: When first shown for a displayed tour, the presentation-view mode selector MUST default to the mode the tour was optimized with, and MUST clearly indicate the currently selected mode at all times.
- **FR-008**: Changing the selected mode in the presentation view MUST reload the available-driver list for the newly selected mode, showing the drivers who qualify for that mode with their projected workday computed for it.
- **FR-009**: Changing the selected mode in the presentation view MUST only reload the driver list (and recompute a subsequently selected driver's workday preview for the selected mode); it MUST NOT re-optimize or re-trace the displayed candidate tour, whose stop order and on-map polyline stay as computed for the mode the tour was optimized with. (Resolved: presentation-layer edit only — see Assumptions.)
- **FR-010**: When the mode change reloads the driver list, any currently selected driver / previewed workday MUST be cleared, consistent with feature 014's rule that a driver-list reload clears the selection.
- **FR-011**: While the reloaded driver list is pending, the system MUST NOT show a stale list from a previously selected mode; the list reflecting the latest selected mode MUST win.
- **FR-012**: Selecting a mode that has no qualifying drivers MUST show the existing "no one available for this delivery" state rather than an error.

### Key Entities *(include if feature involves data)*

- **Presentation-view selected mode**: the transport mode currently chosen in the result view, used to filter and project the available-driver list. Initialized from the tour's optimization mode; changeable without leaving the result view.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of "New tour" activations from a displayed tour surface a confirmation before any tour data is discarded.
- **SC-002**: Cancelling the new-tour confirmation preserves the displayed tour in 100% of cases (no observable change to stops, mode, driver list, or selection).
- **SC-003**: For each of the three modes, selecting it in the presentation view reloads the driver list to the set of drivers available for that mode (verifiable by the mode used in the driver query).
- **SC-004**: A planner can compare driver availability across all three modes for one optimized tour without leaving the result view or re-running optimization.
- **SC-005**: On first display of a tour's result view, the mode selector shows the tour's optimization mode as its initial value in 100% of sessions.

## Assumptions

- "Make a new tour" / "drop the current on-going tour" refers to the existing "New tour" action in the result view (today an immediate reset). This feature inserts a confirmation before that reset; it does not add a new-tour entry point elsewhere. There is no equivalent action to confirm in the editing view (no tour is on-going there).
- The available-driver endpoint (feature 006) already accepts and filters by mode, and the result view already fetches the driver list keyed by mode; this feature exposes a mode control in the result view and wires it to that existing query rather than adding new driver-availability logic.
- The confirmation dialog reuses the shared dialog primitive and role-named styling already used by the assignment confirmation (feature 012), per the project constitution (shared classes, role-named colors, no new bespoke modal).
- The presentation-view mode selection is session/tour-scoped and not persisted; each newly displayed tour starts its selector at that tour's optimization mode.
- **Presentation mode drives the driver list only (FR-009 resolution)**: the candidate tour's stop order and polyline are a fixed property of the optimization that produced it; the presentation-view mode is purely a "who is available / what is their projected day" filter. Switching it never re-optimizes or re-traces the shown route. This is a deliberate, lightweight presentation-layer edit; a small mode mismatch between the shown route and the driver list is acceptable because the route is not the subject of the switch. (Re-tracing or re-optimizing was rejected as out of scope and contrary to the "quick switch to compare drivers" intent.)
- The editing-view mode selector (feature 003) remains where it is; this feature adds a selector to the result view and does not remove or relocate the editing one. The editing-view mode still determines the mode a tour is **optimized** with.
