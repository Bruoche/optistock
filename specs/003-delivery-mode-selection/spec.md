# Feature Specification: Delivery Mode Selection

**Feature Branch**: `003-delivery-mode-selection`

**Created**: 2026-06-08

**Status**: Draft

**Input**: User description: "We can move on to a new feature. Now, we should have the ability to choose the mode of delivery between trucking, driving and walking (in case there are on foot deliveries, for exemple postal deliveries in cities). In the top bar under the map where the validation button sits, there will be on the left a dropdown menu with trucking by default allowing to choose the other modes. And both the tour will be optimized accordingly as well as the polyline shown displayed on the map being in accordance with the selected mode."

## Context

This feature builds on **001 — Delivery Route Optimization** (complete) and **002 — Road-Accurate Route Tracing**. Until now the delivery mode has been fixed to **trucking**, centralised in configuration, with no user-facing selector (002 assumptions explicitly noted "No user-facing mode selector yet"). Both the tour optimization (001) and the road-accurate polyline tracing (002) already accept a `mode` parameter — they have simply been hard-wired to trucking.

This feature exposes that mode as a **user choice** so a planner can pick the form of transport — **trucking**, **driving**, or **walking** (e.g. on-foot postal deliveries inside a city) — and have **both** the optimized stop order **and** the road path drawn on the map reflect the chosen mode.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Choose a delivery mode and optimize the tour for it (Priority: P1)

A delivery planner picks a transport mode from a dropdown beside the validation button, validates, and receives a tour whose stop order is optimized for that mode of travel.

**Why this priority**: Choosing the mode is the core of this feature; the optimized order is the primary deliverable a planner acts on, and trucking-only optimization is wrong for on-foot or car deliveries.

**Independent Test**: Select each of trucking / driving / walking, validate the same set of stops, and confirm the optimization runs using the selected mode (the request that optimizes the tour carries the chosen mode).

**Acceptance Scenarios**:

1. **Given** the planner has set the mode dropdown to "walking", **when** they validate the stops, **then** the tour is optimized for walking.
2. **Given** the dropdown is left at its default, **when** the planner validates, **then** the tour is optimized for trucking.
3. **Given** a tour was already optimized for trucking, **when** the planner changes the mode to "driving" and validates again, **then** a new tour optimized for driving replaces the previous result.

---

### User Story 2 - See the road path drawn for the selected mode (Priority: P1)

After validating, the planner sees the route on the map traced along roads/paths appropriate to the chosen mode — for walking, pedestrian-accessible paths; for driving/trucking, the corresponding road network.

**Why this priority**: A polyline that does not match the chosen mode is misleading (e.g. a walking delivery drawn along a motorway). The displayed path and the optimized order must agree on the same mode to be trustworthy.

**Independent Test**: Optimize the same stops in walking vs trucking and confirm the displayed polyline is retrieved for the selected mode and matches the mode used for that tour's optimization.

**Acceptance Scenarios**:

1. **Given** the planner validated with mode "walking", **when** the road-accurate path is drawn, **then** the polyline follows paths retrieved for walking.
2. **Given** a tour shown on the map, **when** the planner inspects it, **then** the polyline mode and the optimization mode for that tour are the same (never mismatched).

---

### User Story 3 - Trucking as the clear default with a visible current selection (Priority: P2)

When the planner opens the application, the mode dropdown shows trucking selected by default, and at all times the dropdown clearly indicates which mode is currently chosen.

**Why this priority**: These are delivery routes; trucking is the most common case and must be the zero-effort default. A visible current selection prevents the planner from optimizing for the wrong mode by mistake.

**Independent Test**: Open the application and confirm the dropdown reads "trucking" before any interaction; change it and confirm the new selection is shown.

**Acceptance Scenarios**:

1. **Given** a freshly loaded application (the editing view, before any tour is optimized), **when** the planner looks at the control bar beneath the map, **then** the dropdown sits to the left of the validation button and shows "trucking".
2. **Given** the planner selects "driving", **when** they look at the dropdown, **then** it clearly shows "driving" as the active choice.
3. **Given** a tour has been optimized and the result is displayed, **when** the planner views it, **then** the editing controls (dropdown + validation button) are replaced by the result view; the planner returns to editing (where the dropdown reappears, defaulted to trucking) by resetting.

---

### Edge Cases

- **Mode changed after a tour is displayed**: the dropdown is part of the editing view and is not shown once a result is displayed, so the mode cannot be changed against a displayed tour. After a reset, the planner is back in the editing view and the next validation uses the then-selected mode. The displayed tour always reflects the mode it was validated with.
- **No route for the selected mode**: a mode may have no valid path for some leg (e.g. walking across water, a stop only reachable by a motorway). The result must fail gracefully per 002 (straight-line fallback for that leg, failure logged) and must not silently present a path from a different mode.
- **Selected mode not supported by the routing service**: if the routing/optimization service rejects a mode, the failure must be surfaced and logged, not hidden.
- **Validation with no/insufficient stops**: mode selection does not change existing rules for when validation is allowed; the dropdown is still usable.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST present a delivery-mode dropdown in the control bar beneath the map (the editing view), positioned to the left of the validation button.
- **FR-002**: The dropdown MUST offer exactly three modes: **trucking**, **driving**, and **walking**.
- **FR-003**: The dropdown MUST default to **trucking** when the application is first loaded, with no user action required.
- **FR-004**: Whenever the dropdown is shown (the editing view), it MUST clearly indicate the currently selected mode. The dropdown is part of the editing controls and is not shown once a tour result is displayed.
- **FR-005**: When the planner validates, the system MUST optimize the tour using the mode currently selected in the dropdown.
- **FR-006**: When the road-accurate path is drawn for a validated tour, the system MUST retrieve and display the polyline for the same mode that tour was optimized with.
- **FR-007**: The optimization mode and the displayed polyline mode for any given tour MUST always match; the system MUST NOT display a tour optimized for one mode with a path traced for another.
- **FR-008**: Changing the selected mode MUST NOT alter a tour already displayed; the new mode MUST take effect on the next validation.
- **FR-009**: When the selected mode yields no valid route (whole-tour or per-leg), the system MUST fall back and log the failure consistent with 002's progressive-enhancement behavior, and MUST NOT substitute a path from a different mode.
- **FR-010**: The system MUST replace the previously hard-wired trucking configuration with the user-selected mode as the source of truth for both optimization and tracing, while keeping trucking as the default value.

### Key Entities *(include if feature involves data)*

- **Delivery Mode**: the chosen form of transport for a tour — one of `trucking` (default), `driving`, `walking` — used as input to both tour optimization and road-path tracing.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On first load, 100% of sessions show the mode dropdown defaulted to trucking, positioned left of the validation button.
- **SC-002**: For each of the three modes, validating the same stops produces a tour optimized with that mode (verifiable by the mode used in the optimization).
- **SC-003**: For every displayed tour, the polyline mode matches the optimization mode 100% of the time.
- **SC-004**: A planner can switch between all three modes and obtain a corresponding optimized tour with no errors in the standard flow.
- **SC-005**: When a selected mode produces no route, the result degrades gracefully (no blank/broken state, no foreign-mode path) and the failure is discoverable in the logs.

## Assumptions

- Both the optimization service (001) and the road-tracing `/route` endpoint (002) already accept a `mode` parameter and support the values `trucking`, `driving`, and `walking`; this feature wires that parameter to a UI control rather than introducing new routing capability. The three mode identifiers MUST be confirmed against the live API before implementation (per 002's lesson on unverified API contracts).
- The validation ("Optimize") action already exists (currently the full-width button atop the stop list in the editing view). This feature introduces a control bar beneath the map holding the mode dropdown to the **left** of that button, reusing existing styling per the constitution (shared classes, role-named colors). The dropdown belongs to the editing view and is not shown once a result is displayed.
- The selected mode is **not persisted across sessions**; each new session starts at the trucking default. (No requirement was stated for persistence.)
- The mode unit/semantics (metres/seconds, server-side key handling) follow 002 unchanged; only the mode value becomes user-driven.
- This feature does not change how stops are picked, added, or removed, nor when validation is permitted — only which mode drives optimization and tracing.
