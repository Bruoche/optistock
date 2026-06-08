# Feature Specification: Tour Loop Toggle

**Feature Branch**: `004-tour-loop-toggle`

**Created**: 2026-06-08

**Status**: Draft

**Input**: User description: "The OpenStreet API allows to select either tour that loop back or single line tours without return. So far we always make tour loop, we should have a new toggle button that is by default true to have tours loop or not. It will be placed next to the dropdown menu for tour mode. The route shown after will also take that into account, not showing the final looping segment."

## Context

This feature builds on **001 — Delivery Route Optimization**, **002 — Road-Accurate Route Tracing**, and **003 — Delivery Mode Selection**. Today every optimized tour is **closed**: it returns to the origin, and the displayed route draws the final segment from the last stop back to the first. The routing service also supports an **open** tour — a one-way itinerary with a defined start and end and **no return**.

This feature exposes that choice as a **toggle** beside the delivery-mode dropdown (003) in the control bar beneath the map. The toggle defaults to **on** (loop / closed tour, the current behaviour). When turned **off**, the tour is optimized as an open one-way route, and the displayed road path omits the final looping segment back to the origin.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Choose whether the tour returns to the origin (Priority: P1)

A delivery planner flips a toggle beside the mode dropdown, validates, and gets a tour optimized either as a closed loop (returns to the start) or as an open one-way route (no return).

**Why this priority**: Whether the driver must return to the depot fundamentally changes the optimal stop order and the trip length; a one-way courier run is a different itinerary from a return loop.

**Independent Test**: Optimize the same stops with the toggle on vs off and confirm the optimization is performed as a closed tour in the first case and an open tour in the second (the request that optimizes the tour carries the chosen loop setting).

**Acceptance Scenarios**:

1. **Given** the loop toggle is on (default), **when** the planner validates, **then** the tour is optimized as a closed loop that returns to the origin.
2. **Given** the planner turns the loop toggle off, **when** they validate, **then** the tour is optimized as an open one-way route with no return to the origin.

---

### User Story 2 - See the route drawn without the return segment when looping is off (Priority: P1)

After validating with looping off, the planner sees the route traced from the first to the last stop only — the final segment back to the origin is not drawn.

**Why this priority**: A drawn return segment on a one-way run is misleading; the displayed path must match the actual itinerary the driver follows.

**Independent Test**: Optimize the same stops with looping on vs off and confirm the on case draws the closing segment back to the origin while the off case ends at the last stop.

**Acceptance Scenarios**:

1. **Given** a tour validated with looping on, **when** the route is drawn, **then** it includes the segment from the last stop back to the first.
2. **Given** a tour validated with looping off, **when** the route is drawn, **then** it ends at the last stop and shows no segment returning to the origin.
3. **Given** a tour shown on the map, **when** the planner inspects it, **then** the route's loop state matches the loop state the tour was optimized with (never mismatched).

---

### User Story 3 - Loop toggle defaults to on, beside the mode dropdown (Priority: P2)

When the planner opens the application, the loop toggle shows "on" by default and sits next to the delivery-mode dropdown in the control bar; its current state is always clear while editing.

**Why this priority**: Looping is the most common delivery case (return to depot) and must be the zero-effort default; a clear, well-placed control prevents optimizing the wrong tour shape by mistake.

**Independent Test**: Open the application and confirm the toggle reads "on" and sits beside the mode dropdown before any interaction; flip it and confirm the new state is shown.

**Acceptance Scenarios**:

1. **Given** a freshly loaded application (the editing view), **when** the planner looks at the control bar beneath the map, **then** the loop toggle sits next to the mode dropdown and is on.
2. **Given** the planner turns the toggle off, **when** they look at it, **then** it clearly shows the off (no-return) state.

---

### Edge Cases

- **Two-stop tour**: with looping on, the route is A→B→A (out and back); with looping off, it is a single A→B segment with no return.
- **Toggle changed after a tour is displayed**: the toggle is part of the editing view and is not shown once a result is displayed, so the loop setting cannot be changed against a displayed tour. After a reset the planner is back in editing; the next validation uses the then-current setting. The displayed tour always reflects the setting it was validated with.
- **Combination with delivery mode (003)**: the loop toggle is independent of the mode; any of trucking/driving/walking can be optimized as either a closed loop or an open tour.
- **Travel metrics**: an open tour's total distance/duration excludes the return leg; a closed tour's totals include it.
- **No route for a leg**: per 002, a leg with no path falls back to a straight segment and is logged; the loop setting only governs whether the closing leg exists, not how leg failures are handled.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST present a loop toggle in the control bar beneath the map (the editing view), positioned next to the delivery-mode dropdown.
- **FR-002**: The toggle MUST default to **on** (closed loop / return to origin) when the application is first loaded, with no user action required.
- **FR-003**: Whenever the toggle is shown (the editing view), it MUST clearly indicate its current state. The toggle is part of the editing controls and is not shown once a tour result is displayed.
- **FR-004**: When the toggle is on, the system MUST optimize the tour as a closed loop that returns to the origin (the current behaviour).
- **FR-005**: When the toggle is off, the system MUST optimize the tour as an open one-way route with no return to the origin.
- **FR-006**: When the road-accurate route is drawn, the system MUST reflect the loop setting — including the final segment back to the origin when on, and omitting it when off.
- **FR-007**: The loop setting used to optimize a tour and the loop state of its displayed route MUST always match; the system MUST NOT display a tour optimized one way with a route drawn the other.
- **FR-008**: Changing the toggle MUST NOT alter a tour already displayed; the new setting MUST take effect on the next validation.
- **FR-009**: The displayed travel distance and duration MUST reflect the loop setting — an open tour's totals exclude the return leg; a closed tour's totals include it.
- **FR-010**: The loop toggle MUST work in combination with every delivery mode (003); the two choices are independent.

### Key Entities *(include if feature involves data)*

- **Loop Preference**: whether a tour returns to its origin — `looped` (closed, default) or `open` (one-way, no return) — used as input to both tour optimization and route tracing.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On first load, 100% of sessions show the loop toggle on, positioned beside the mode dropdown.
- **SC-002**: Validating the same stops with the toggle off produces an open tour whose drawn route has no return segment; with the toggle on, the route includes it.
- **SC-003**: For every displayed tour, the route's loop state matches the optimization loop state 100% of the time.
- **SC-004**: Both loop states can be selected in combination with each of the three delivery modes and produce a corresponding tour with no errors in the standard flow.
- **SC-005**: An open tour's reported total distance/duration excludes the return leg, while a closed tour's includes it.

## Assumptions

- The OpenStreet optimization endpoint accepts a parameter selecting a closed vs open tour (today the system always requests a closed tour). **The exact request value for an open tour is UNVERIFIED and MUST be confirmed against the live API before implementation** (per the 002/003 lesson on unverified API contracts).
- The road-tracing flow (002) already traces the tour leg by leg, including the closing leg; producing an open route is a matter of omitting that closing leg both in optimization and in tracing — no new tracing capability is introduced.
- Like the delivery mode (003), the loop setting affects the optimized result, so it is a tour-shape dimension that participates in result caching and is snapshotted with a displayed tour so its route stays congruent (an implementation concern for planning).
- The toggle is an editing-view control beside the mode dropdown (003); its value is retained across a reset within a session and is not persisted across sessions (each new session starts at the default, on).
- This feature does not change how stops are picked, added, or removed, nor when validation is permitted — only the tour shape (looped vs open) that drives optimization and tracing.
