# Feature Specification: Delivery Driver Assignment

**Feature Branch**: `006-driver-assignment`

**Created**: 2026-06-09

**Status**: Draft

**Input**: User description: "New feature, we will now add the ability to assign delivery drivers to the tour after optimizing a new route. Drivers will have a name, an image (like a profile icon) and a list of available tour modes (walking, driving or trucking). They can provide at least one tour mode, and can not provide all of them. When a tour is optimized, we get a list of available drivers (those with the correct mode available for now) for the tour. This list will be presented in the same place the list of coordinates was in the edit page, but this time for the results page. The name is the most visible part, with the available modes presented via icons (stickman walking, car, truck icons), showing the icons corresponding to the available modes the driver can provide under their name. Later we will add other constraints and infos in the lists that relate to time, but for now that's all for this feature's scope will cover."

## Context

Tours are already optimized with a chosen **delivery mode** (walking, driving, or trucking — feature 003). This feature introduces **delivery drivers** and surfaces, on the **results page**, the drivers that can actually run the just-optimized tour. A driver carries a name, a profile image, and the set of tour modes they can provide (at least one, but never all three). After optimization, the system filters drivers down to those whose available modes include the tour's mode and presents them as a list — placed where the coordinate list sat on the edit page, but now on the results page. Each entry leads with the driver's name and shows mode icons (walking figure, car, truck) for the modes that driver supports.

This feature's scope is **listing** the available drivers only. Actually selecting/assigning a specific driver to the tour, and time-related constraints and information, are explicitly **out of scope** and will come in later features.

## Clarifications

### Session 2026-06-09

- Q: What message is shown when no driver supports the tour's mode? → A: "No one available for this delivery."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See drivers that can run the optimized tour (Priority: P1)

After optimizing a tour, the user sees, on the results page, a list of the drivers available for that tour — only those whose supported modes include the tour's delivery mode. Each entry shows the driver's name prominently with icons beneath it for the modes that driver supports.

**Why this priority**: Surfacing the matching drivers is the core deliverable of this feature; without the list there is nothing to act on.

**Independent Test**: Optimize a tour with a given delivery mode and confirm the results page lists exactly the drivers whose available modes include that mode, each showing their name and the correct mode icons.

**Acceptance Scenarios**:

1. **Given** an optimized tour with delivery mode "driving", **when** the user views the results page, **then** the list shows every driver whose available modes include "driving", and no driver who lacks it.
2. **Given** a driver in the list, **when** the user views their entry, **then** the driver's name is the most prominent element and the icons for that driver's supported modes (walking figure / car / truck) appear beneath the name.
3. **Given** the results page, **when** the driver list renders, **then** it occupies the same location that the coordinate list occupied on the edit page.
4. **Given** an optimized tour, **when** no driver supports the tour's mode, **then** the message "No one available for this delivery." is shown in place of the list rather than appearing broken or blank.

---

### Edge Cases

- **No matching driver**: the tour's mode is supported by no driver — show the message "No one available for this delivery." in place of the list, not a blank or broken list.
- **Single matching driver**: the list still renders normally with one entry.
- **Driver with two modes**: both supported-mode icons appear; the unsupported mode's icon does not.
- **Missing driver image**: a profile-icon placeholder is shown so the entry stays well-formed.
- **Re-optimizing with a different mode**: the available-driver list refreshes to match the new mode.
- **Long driver name**: the name stays the most prominent element without breaking the entry's layout.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A driver MUST have a name, a profile image, and a set of supported tour modes drawn from {walking, driving, trucking}.
- **FR-002**: A driver's supported-mode set MUST contain at least one mode and MUST NOT contain all three (i.e. one or two modes only).
- **FR-003**: After a tour is optimized, the system MUST determine the list of **available drivers** for that tour as those whose supported modes include the tour's delivery mode.
- **FR-004**: The results page MUST present the available-driver list in the same location that the coordinate list occupied on the edit page.
- **FR-005**: Each driver entry MUST display the driver's name as its most prominent element.
- **FR-006**: Each driver entry MUST display, beneath the name, an icon for each mode that driver supports — a walking-figure icon for walking, a car icon for driving, a truck icon for trucking — and MUST NOT show icons for modes the driver does not support.
- **FR-007**: When no driver supports the tour's delivery mode, the results page MUST display the exact message "No one available for this delivery." in place of the list.
- **FR-008**: When a driver has no usable image, the entry MUST fall back to a profile-icon placeholder.
- **FR-009**: When the tour is re-optimized with a different delivery mode, the available-driver list MUST update to reflect the new mode.
- **FR-010**: The driver list MUST NOT include time-related constraints or information; those are out of scope for this feature.
- **FR-011**: This feature MUST only list available drivers; selecting or assigning a specific driver to the tour is out of scope and MUST NOT be implemented here.

### Key Entities *(include if feature involves data)*

- **Driver**: a person who can run a tour. Attributes: name, profile image, and a set of supported tour modes (1 or 2 of {walking, driving, trucking}; never all three).
- **Available-driver list**: the subset of drivers, computed for a specific optimized tour, whose supported modes include that tour's delivery mode. Relates a Tour (with its delivery mode) to the Drivers eligible to run it.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For any optimized tour, the displayed list contains 100% of drivers whose supported modes include the tour's mode and 0% of drivers who do not support it.
- **SC-002**: Every driver entry shows the driver's name as the dominant element and exactly the icons for that driver's supported modes — no missing and no extra icons.
- **SC-003**: When no driver supports the tour's mode, the message "No one available for this delivery." is shown in 100% of such cases (never a blank or broken list).
- **SC-004**: The driver list appears in the results page at the same position the coordinate list held on the edit page, as confirmed by side-by-side comparison.
- **SC-005**: Re-optimizing with a different mode refreshes the list to the correct matching set with no stale entries.

## Assumptions

- **Driver data already exists**: drivers (with their names, images, and supported modes) are pre-existing/seeded data. Creating, editing, or deleting drivers (a management UI) is **out of scope** for this feature.
- **One delivery mode per tour**: the optimized tour has a single delivery mode (from feature 003), and matching is a simple "driver's modes include the tour's mode" check.
- **Mode set is fixed**: the only tour modes are walking, driving, and trucking, consistent with the existing delivery-mode feature.
- **Results page placement**: "the same place the list of coordinates was" refers to the coordinate/stop list region used on the edit page, reused for the results page layout.
- **Listing only**: choosing/assigning a specific driver to the tour is deferred to a later feature; this feature only surfaces the available-driver list.
- **No time data yet**: time-related constraints and information are deferred to a later feature and intentionally excluded here.
- **Icons**: walking is represented by a walking-figure ("stickman") icon, driving by a car icon, trucking by a truck icon.
