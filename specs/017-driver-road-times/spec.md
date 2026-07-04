# Feature Specification: Driver Road-Time Breakdown

**Feature Branch**: `017-driver-road-times`

**Created**: 2026-07-04

**Status**: Draft

**Input**: User description: "We will now add more informations for the manager to make their choice. for each row of the driver, we will in grey (same color as the text for \"PROJECTED\") add the fields \"Road to tour\" that will tell us how much time is spent from the warehouse/last tour to the projected tour, and \"Road to warehouse\" that will tell us how much time is spent from the projected tour back to the warehouse. These information will appear on the right, in this order, to the left of the \"PROJECTED\" field. This \"PROJECTED\" field will also be renamed as \"Total projected workday\", so it is clearer."

## Context

In the presentation view, each available-driver row already shows one figure on the right: the driver's whole projected working day for the candidate tour, labelled **PROJECTED** (feature 013). That single total hides *where* the time goes. This feature breaks out the two travel legs that bracket the candidate tour so a manager can weigh drivers by how much dead-heading each one incurs:

- **Road to tour** — time from the driver's starting point that day (their **warehouse**, or the **end of their last already-assigned tour**) to the **projected (candidate) tour**.
- **Road to warehouse** — time from the **projected tour** back to the driver's **warehouse**.

Both are already computed when the projected total is assembled (they are two of the connections summed into it); this feature surfaces them as their own figures. The existing total is kept but relabelled **Total projected workday** for clarity.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Compare drivers by their dead-heading time (Priority: P1)

A delivery manager viewing the available-driver list for a tour sees, on each driver's row, how long that driver would spend driving **to** the tour and **back to** their warehouse, alongside the total projected workday — so they can favour a driver who wastes less time off-tour even if totals look similar.

**Why this priority**: The whole feature is this added decision information; without the two breakout figures there is nothing new to deliver.

**Independent Test**: Open the driver list for a tour with at least two drivers based at different warehouses and confirm each row shows a "Road to tour" and a "Road to warehouse" time that differ per driver according to their distance from the tour.

**Acceptance Scenarios**:

1. **Given** the driver list is shown for a tour, **when** a manager looks at a driver's row, **then** the row shows a "Road to tour" time (warehouse or last-tour end → projected tour) and a "Road to warehouse" time (projected tour → warehouse), in addition to the total.
2. **Given** a driver who already has an earlier tour assigned that day, **when** the manager reads that driver's "Road to tour", **then** it measures travel from the **end of that last tour** (not the warehouse) to the projected tour.
3. **Given** a driver with no earlier tour that day, **when** the manager reads "Road to tour", **then** it measures travel from the driver's **warehouse** to the projected tour.

---

### User Story 2 - Clearer labelling of the total (Priority: P2)

The single total figure is relabelled from "PROJECTED" to "Total projected workday" so a manager immediately understands it is the whole day, not just one leg — especially now that two partial road times sit beside it.

**Why this priority**: Cheap clarity win that prevents the new breakout figures from being mistaken for parts of a still-cryptic "PROJECTED"; secondary to actually showing the new data.

**Independent Test**: Open the driver list and confirm the total figure reads "Total projected workday" and no longer reads "PROJECTED".

**Acceptance Scenarios**:

1. **Given** the driver list is shown, **when** the manager reads the right-hand figures, **then** the workday total is labelled "Total projected workday".
2. **Given** the three right-hand figures, **when** the manager scans them left to right, **then** they appear in the order: Road to tour, Road to warehouse, Total projected workday.

---

### Edge Cases

- **A bracketing connection cannot be routed**: if the travel time to the tour or back to the warehouse is unknown (the routing for that leg failed), the corresponding figure is shown as unavailable rather than a misleading zero — consistent with how the total already marks itself approximate when a leg is unknown (feature 013). The total keeps its existing approximate treatment.
- **Warehouse coincides with the tour start/end** (zero travel): the figure reads as a zero-length duration in the normal format, not blank.
- **The two road figures plus the tour’s own time should reconcile with the total**: for a driver with no prior tours, Road to tour + the tour’s own duration + Road to warehouse equals the Total projected workday (subject to unknown legs). With prior tours, the total additionally includes the earlier tours and the connections between them, which are not broken out here.
- **Narrow row width**: three figures now sit where one did; they must remain readable and correctly ordered on the row.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Each driver row MUST display a **Road to tour** figure: the travel time from the driver's starting point for the day — their **warehouse**, or the **end of their last already-assigned tour** that day if any — to the **projected (candidate) tour**.
- **FR-002**: Each driver row MUST display a **Road to warehouse** figure: the travel time from the **projected tour** back to the driver's **warehouse**.
- **FR-003**: Both new figures MUST be presented in the same muted/grey text style already used for the existing "PROJECTED" figure's label.
- **FR-004**: On each row the right-hand figures MUST appear, left to right, in this order: **Road to tour**, **Road to warehouse**, **Total projected workday**.
- **FR-005**: The existing total figure MUST be relabelled from "PROJECTED" to **Total projected workday**; its value and meaning (the whole chained day) are unchanged.
- **FR-006**: The two new figures MUST use the same duration formatting (hours/minutes) as the total figure.
- **FR-007**: When a bracketing connection's travel time is unknown/unroutable, the corresponding new figure MUST be shown as unavailable (not zero), and the total MUST retain its existing approximate marking.
- **FR-008**: The new figures MUST reflect the **same** travel times the projected total is built from (the connection into the candidate tour, and the connection from the candidate tour back to the warehouse) — never a separately computed or inconsistent value.
- **FR-009**: The new figures MUST update together with the rest of the row whenever the driver list reloads (e.g. tour, date, or mode change) — they are per-row, per-candidate values like the total.

### Key Entities *(include if feature involves data)*

- **Road to tour (per driver, per candidate tour)**: travel time from the driver's incoming point (warehouse, or last prior tour's end) to the projected tour's chosen start. One of the connections already inside the projected total.
- **Road to warehouse (per driver, per candidate tour)**: travel time from the projected tour's chosen end back to the driver's warehouse. The final connection of the projected total.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For every driver row, the manager can read a distinct "Road to tour" and "Road to warehouse" time in addition to the total — three figures per row.
- **SC-002**: For a driver with no prior tours and all legs routable, "Road to tour" + the tour's own duration + "Road to warehouse" equals the "Total projected workday" shown.
- **SC-003**: 100% of driver rows label the total as "Total projected workday" and none as "PROJECTED".
- **SC-004**: When a bracketing leg is unroutable, its figure shows as unavailable (no zero, no blank) in 100% of such cases.
- **SC-005**: Drivers based farther from the tour show a correspondingly larger "Road to tour"/"Road to warehouse", letting a manager rank drivers by off-tour travel at a glance.

## Assumptions

- The two travel times already exist in the projected-day computation (the connection into the candidate tour and the return connection to the warehouse are among the durations summed into the current total). This feature exposes those two values per row; it does not introduce a new routing calculation or change how the total is computed.
- "Grey (same color as the text for PROJECTED)" refers to the existing muted label styling on the row; the new figures reuse that same role-named style rather than introducing a new colour.
- "Last tour" means the driver's most recent tour already assigned for the selected date; if none, the warehouse is the origin (matching how the projected total already picks the incoming point).
- Unknown/unroutable legs follow the existing convention: shown as unavailable and keeping the total's approximate flag; this feature does not change failure handling, only adds two display fields.
- The two new figures are display-only additions to the existing row; no change to driver ordering, selection, workday map preview, or assignment behaviour.
- Only the two connections bracketing the **candidate** tour are broken out; connections between a driver's earlier tours remain folded into the total and are out of scope for this feature.
