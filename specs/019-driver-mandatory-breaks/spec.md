# Feature Specification: Mandatory Driver Breaks

**Feature Branch**: `019-driver-mandatory-breaks`

**Created**: 2026-07-04

**Status**: Draft

**Input**: User description: "We are now going to take into account the mandatory breaks for drivers in a workday. For every 4:30 hours a driver drives, they must take at least 45 minutes of break. And, for every workday above 6h (non-driving included), they must take a break of at least 30 minutes. Likewise, if they work for more than 9h that break will be 45 minutes. The 45 minute break counts in the workday break too … max(workday_break, (driving_time//4:30)). That amount is added to the Projected Workday displayed. If the projected tour causes a threshold to be reached, a new field \"Required break\" is shown (hidden if = 0), to the left of \"To tour\"/\"To warehouse\"/…, in highlight orange, prepended with \"+\" to show it is the amount gained by adding this tour."

## Context

Each available-driver row shows the driver's **Projected workday** — the whole chained day if this candidate tour were assigned (warehouse → prior tours → candidate tour → warehouse), plus the "To tour" / "To warehouse" road times (features 013–018). That total counts only working time. Real driving law requires **rest breaks**, so the displayed day currently understates how long the driver is actually tied up.

This feature folds the **legally mandated break time** into the Projected workday, and surfaces — only when relevant — how much break the candidate tour is *responsible for adding*:

- **Workday break** (all time, driving + stops + inter-tour travel): a day over **6 h** requires **30 min**; a day over **9 h** requires **45 min**.
- **Driving break**: every completed **4 h 30 min** of *driving* requires **45 min** (cumulative — 9 h driving ⇒ 90 min).
- The day's **total mandatory break** is the **larger** of the two (a driving break already counts toward the workday break — they are not summed): `break = max(workday_break, driving_break)`.
- The total break is **added to the Projected workday** figure.
- A new **Required break** figure shows the **marginal** break this candidate tour introduces (the day's break *with* the candidate minus the break the driver's prior tours already required) — hidden when zero, shown in highlight orange, prefixed "+".

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Projected workday reflects mandatory breaks (Priority: P1)

A delivery manager comparing drivers sees each driver's **Projected workday** already include the rest breaks the law forces, so the figure is a realistic "how long this driver is committed" rather than pure working time.

**Why this priority**: The projected total is the primary decision figure; if it omits legally-required breaks it misleads every assignment decision. This is the core of the feature.

**Independent Test**: Assign the driver list a candidate tour whose day crosses a break threshold and confirm the Projected workday figure is larger than the raw working time by exactly the computed break; confirm a short day (under all thresholds) is unchanged.

**Acceptance Scenarios**:

1. **Given** a driver whose projected day (with the candidate) totals over 6 h but at most 9 h of work and under 4 h 30 min of driving, **when** the manager reads the row, **then** the Projected workday includes an added **30 min** break.
2. **Given** a driver whose projected day totals over 9 h of work, **when** the manager reads the row, **then** the Projected workday includes an added **45 min** break (not 30).
3. **Given** a driver who would drive over 4 h 30 min (e.g. 5 h) but whose total day is under 6 h, **when** the manager reads the row, **then** the Projected workday includes an added **45 min** break from the driving rule even though the workday rule alone would add none.
4. **Given** a driver who drives 9 h 15 min in the day, **when** the manager reads the row, **then** the driving break is **90 min** (two completed 4 h 30 min blocks), and the day's total break is the larger of that and the workday break.
5. **Given** a driver whose day is under 6 h total and under 4 h 30 min driving, **when** the manager reads the row, **then** no break is added and the Projected workday equals the raw working time.

---

### User Story 2 - "Required break" shows the marginal break this tour adds (Priority: P2)

When assigning this candidate tour is what pushes the driver across a break threshold, the manager sees a distinct **Required break** figure — in highlight orange, prefixed "+" — telling them how much extra break the tour is responsible for; when the tour adds no new break, the figure is hidden.

**Why this priority**: Highlights the *marginal* legal cost of this specific assignment so a manager can avoid the driver the tour would newly burden; secondary to the corrected total, and only meaningful once US1's break math exists.

**Independent Test**: Pick a driver whose prior tours already require some break and a candidate that raises it; confirm the "Required break" shows exactly the increase with a leading "+", in the highlight orange style, to the left of "To tour"; pick a candidate that adds no break and confirm the figure is absent.

**Acceptance Scenarios**:

1. **Given** a candidate tour that raises the driver's mandated day break from 0 to 30 min, **when** the manager reads the row, **then** a "Required break" figure reads "+30 min".
2. **Given** a driver whose prior tours already require 30 min of break and a candidate that raises the day's requirement to 45 min, **when** the manager reads the row, **then** "Required break" reads "+15 min" (the increase only).
3. **Given** a candidate tour that does not push the driver across any threshold (the day's break is unchanged by adding it), **when** the manager reads the row, **then** no "Required break" figure is shown.
4. **Given** a "Required break" figure is shown, **when** the manager scans the right-hand figures, **then** it appears to the **left** of "To tour", "To warehouse", and "Projected workday", styled in the highlight orange used for emphasis.

---

### Edge Cases

- **Break does not recurse**: thresholds are measured on working/driving time only; the added break itself never counts toward the 6 h / 9 h / 4 h 30 min thresholds (a 5 h 50 min work day plus a 30 min break is not treated as a 6 h 20 min day requiring more break).
- **Driving vs non-driving split**: driving time is all road travel (inter-tour connections + each tour's own travel), non-driving is stop/service time. A day dominated by long stops can trip the workday break while barely driving — the workday rule then dominates the `max`.
- **Whichever is bigger**: when the driving break and workday break differ, only the larger is applied — never the sum (the description's "the 45 min break counts in the workday break too").
- **No prior tours**: the "without candidate" day is empty, so the whole of the candidate day's break is the marginal "Required break".
- **Unknown/unroutable travel** (existing approximate projection): break is computed on the best-effort known time and the Projected workday keeps its existing approximate marking; the break may be understated when travel is unknown.
- **Exactly on a threshold**: 6 h and 9 h are strict — a day of *exactly* 6 h adds no workday break; *just over* 6 h adds 30 min. Driving uses completed blocks — exactly 4 h 30 min driving is one block (45 min); 4 h 29 min is none.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST compute a **workday break** from the projected day's total working time: **0** when the day is 6 h or less, **30 min** when over 6 h and up to 9 h, **45 min** when over 9 h.
- **FR-002**: The system MUST compute a **driving break** of **45 min for every completed 4 h 30 min of driving time** in the projected day (cumulative: `floor(driving_time / 4h30) × 45 min`).
- **FR-003**: The day's **total mandatory break** MUST be the larger of the workday break and the driving break (`max`), never their sum.
- **FR-004**: The **Projected workday** figure MUST include the total mandatory break added to the working time.
- **FR-005**: Driving time MUST count all road travel (inter-tour connection drives plus each tour's own travel); non-driving time (stop/service durations) MUST NOT count toward the driving break but MUST count toward the workday break.
- **FR-006**: The mandatory break MUST be derived from working/driving time only; the added break MUST NOT itself feed back into the 6 h / 9 h / 4 h 30 min threshold checks.
- **FR-007**: Each driver row MUST display a **Required break** figure equal to the **increase** in the day's total mandatory break caused by adding the candidate tour: `break(day with candidate) − break(prior tours only)`.
- **FR-008**: The Required break figure MUST be **hidden when the increase is zero** and shown otherwise.
- **FR-009**: When shown, the Required break MUST be prefixed with **"+"** and use the same duration formatting as the other figures, styled in the **highlight orange** emphasis role.
- **FR-010**: When shown, the Required break MUST appear to the **left** of the existing "To tour", "To warehouse", and "Projected workday" figures.
- **FR-011**: All break-derived values MUST recompute together with the rest of the row whenever the driver list reloads (tour, date, or mode change).
- **FR-012**: When the projection is approximate (some travel unknown), the break MUST be computed on the known time and the Projected workday MUST keep its existing approximate marking.

### Key Entities *(include if feature involves data)*

- **Workday break (per driver, per candidate)**: rest minutes required by the total day length (0 / 30 / 45 by the 6 h / 9 h thresholds).
- **Driving break (per driver, per candidate)**: rest minutes required by driving time (45 min per completed 4 h 30 min).
- **Total mandatory break (per driver, per candidate)**: `max(workday break, driving break)`, added to the Projected workday.
- **Required break (per driver, per candidate)**: the marginal increase in total mandatory break caused by the candidate tour; displayed only when positive.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100 % of driver rows, the Projected workday equals working time plus `max(workday break, driving break)` under the stated thresholds.
- **SC-002**: A day over 6 h (≤ 9 h) adds exactly 30 min; a day over 9 h adds exactly 45 min; a day of 6 h or less adds 0 — verifiable at and around each boundary.
- **SC-003**: Driving over 4 h 30 min adds at least 45 min even when the workday rule alone would add nothing; 9 h+ of driving adds 90 min.
- **SC-004**: The Required break equals the day's break increase from the candidate and is hidden exactly when that increase is 0 — correct in 100 % of rows.
- **SC-005**: When shown, the Required break reads "+" followed by the increase, in the highlight orange style, left of the other three figures.

## Assumptions

- "Workday" (for the 6 h / 9 h thresholds) is the projected chained day's total working time — the same base the current Projected workday figure sums (inter-tour travel + tour travel + stop/service time), before breaks.
- "Driving time" is all road travel in that day (warehouse↔tour and tour↔tour connections plus each tour's own travel duration); stop/service time is non-driving.
- Thresholds are evaluated on working/driving time excluding the mandatory break itself (breaks do not recursively trigger more break) — the standard interpretation.
- "Over 6 h" and "over 9 h" are strict (a day of exactly the threshold does not trigger the higher tier); the driving rule counts only completed 4 h 30 min blocks.
- The "amount gained by adding this tour" is the marginal break: the day's total break with the candidate minus the total break the driver's already-assigned tours require on their own; with no prior tours, that equals the candidate day's whole break.
- The highlight orange is the existing emphasis/primary role already used elsewhere (e.g. the emphasized candidate path), not a new colour.
- This feature adds break time to the existing Projected workday and one conditional figure; it does not change driver ordering, selection, the map preview, assignment, or the road-time figures themselves.
