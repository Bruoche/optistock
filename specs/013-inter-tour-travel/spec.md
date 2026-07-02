# Feature Specification: Inter-Tour Travel Time

**Feature Branch**: `013-inter-tour-travel`

**Created**: 2026-07-02

**Status**: Draft

**Input**: User description: "As a new feature, we are now going to take into account the time it takes for drivers to go in between tours. Drivers will now be assigned a place they come from each day (the warehouse) and when making the estimate of their totale day it will be the time to get from warehouse to first tour, the sum of the time of each tours, the time to go from each tour to the next, and the time to go from the last tour back to the warehouse. To estimate travel to/from/between tours, we will use stops as starting/ending points. For this, we will now define a starting and ending stop when assigning tours. In looping tours, any stop is a valid start/end stop, and the start and end are both at the same stop (since the tour loop back). For one-way trip, only the two ends of the trip are valid start/stop point, and if one is selected as start the other will de facto be the end point. To select which stop is the starting point, we will evaluate the time it takes to go from the warehouse/last tour to each valid starting stop, and take the closest one."

## Context

Feature 012 introduced assigning a tour to a driver and showing each driver's
**projected working hours** for the selected date — but that figure was
deliberately just the *sum of the assigned tours' durations*; the time a driver
spends **driving between tours, and to/from base**, was left out and marked for
later. This feature closes that gap.

Each driver now has a **warehouse** — the place they depart from and return to
each day. A driver's day is no longer a bare sum of tour durations; it is the
whole chain: drive from the warehouse to the first tour, run each tour, drive
from each tour to the next, and drive from the last tour back to the warehouse.

To measure those connecting drives we need a concrete point on each tour to
leave from and arrive at, so assigning a tour now also fixes a **start stop** and
an **end stop** on it. For a looping tour any stop works and start = end (the
loop returns to it); for a one-way tour only its two endpoints qualify, and
picking one as the start makes the other the end. The system chooses the start
automatically: for each valid start stop it measures the drive from the previous
point (the warehouse, or the prior tour's end stop) and keeps the closest one.

The projected-hours figure from feature 012 now reflects this full chain.

## Clarifications

### Session 2026-07-02

- Q: How is a warehouse defined and associated with drivers, and is it mandatory? → A: **Multiple warehouses supported; each driver has exactly one, and it is mandatory** — a driver can never have zero warehouses (they must collect merchandise somewhere). This adds a warehouse entity and a required driver→warehouse link; assignment/onboarding must guarantee every driver has one.
- Q: How is the sequence of a driver's tours for the date determined? → A: **Assignment order** — each newly assigned tour is assumed to be the next tour the driver runs, appended to the end of the day's chain. Re-ordering a driver's tours is a later, out-of-scope capability.
- Q: How should the many `x drivers × y valid-start` travel-time lookups be executed to keep the presentation load responsive? → A: **Concurrently, deduplicated, and capped.** Collect the **distinct** set of travel legs needed across all drivers (identical warehouse/return/between legs counted once), fetch them in a **bounded-concurrency batch** (a capped pool, so the external routing API is not flooded), then compute each driver's chain from the pre-fetched durations. Deduplication + the cap are required, not just raw parallelism.
- Q: When a connecting travel leg cannot be routed, how should the driver's projected day be presented? → A: **Best-effort total with an inaccuracy flag.** Instead of hiding the whole figure, compute the projected day from the legs that succeeded (a failed leg contributes 0) and mark the figure as **approximate/incomplete**, so the manager sees a usable lower-bound day that is explicitly flagged as possibly understating point-to-point travel. This **supersedes** the earlier all-or-nothing "Unavailable" behavior for the projected day.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Projected day includes travel to, from, and between tours (Priority: P1)

When judging whether to hand a delivery to a driver, the manager sees that
driver's projected day as the full chain — warehouse to the first tour, each
tour's own duration, the hops between successive tours, and the return to the
warehouse — not just the tours' durations added together. This gives a realistic
picture of the driver's working day before committing the assignment.

**Why this priority**: This is the feature's core value — the previous figure
understated real workload by ignoring all driving between and around tours, which
is exactly what a dispatcher needs to see to avoid overloading a driver.

**Independent Test**: For a driver with a warehouse and one or more tours for the
date, verify the projected figure equals warehouse→first-start travel + every
tour's duration + each between-tour travel + last-end→warehouse travel — strictly
greater than the plain sum of durations whenever any connecting drive is non-zero.

**Acceptance Scenarios**:

1. **Given** a driver with a warehouse and no tours yet for the date, **When** the list is shown for a candidate tour, **Then** the projected day equals warehouse→start travel + the tour's duration + end→warehouse travel.
2. **Given** a driver already holding tours for the date, **When** the list is shown for a new candidate tour, **Then** the projected day equals the full chained total across all their tours for the date plus the candidate, including every connecting drive and both warehouse legs.
3. **Given** any driver's projected day, **When** displayed, **Then** it appears in the same human-readable hours/minutes format used elsewhere and is at least the plain sum of the tours' durations.

---

### User Story 2 - Start and end stops are set when a tour is assigned (Priority: P1)

Assigning a tour records which stop the driver arrives at to begin it (start
stop) and which stop they leave from when it ends (end stop), so the connecting
drives can be measured from real points. For a looping tour the start and end are
the same stop; for a one-way tour they are its two endpoints.

**Why this priority**: Without a defined start and end stop there is no anchor
from which to measure travel to, from, or between tours — every travel figure in
User Story 1 depends on these being set correctly per tour type.

**Independent Test**: Assign a looping tour and confirm its start and end stop are
one and the same stop; assign a one-way tour and confirm its start and end are its
two opposite endpoints (never an interior stop).

**Acceptance Scenarios**:

1. **Given** a looping tour, **When** it is assigned, **Then** its start stop and end stop are recorded as the same single stop.
2. **Given** a one-way tour, **When** it is assigned, **Then** its start stop is one endpoint and its end stop is the other endpoint, and no interior stop is chosen as either.
3. **Given** a one-way tour whose start has been chosen, **When** the end is determined, **Then** the end is the opposite endpoint by construction (choosing the start fixes the end).

---

### User Story 3 - Start stop is chosen as the closest valid stop to the incoming point (Priority: P1)

The system does not ask the manager which stop to start from; it picks the valid
start stop that is quickest to reach from the point the driver is coming from —
the warehouse for the first tour of the day, or the previous tour's end stop for
each later tour — minimizing dead driving.

**Why this priority**: The automatic closest-stop choice is what makes the
projected day both realistic and minimal; a fixed or arbitrary start could inflate
the estimate and misinform the assignment decision.

**Independent Test**: Give a tour two valid start candidates at clearly different
distances from the incoming point and confirm the one with the shorter travel time
is selected as the start (and, for a one-way tour, the other becomes the end).

**Acceptance Scenarios**:

1. **Given** the first tour of a driver's day, **When** its start is chosen, **Then** it is the valid stop with the shortest travel time from the warehouse.
2. **Given** a subsequent tour, **When** its start is chosen, **Then** it is the valid stop with the shortest travel time from the previous tour's end stop.
3. **Given** a looping tour with several stops, **When** the start is chosen, **Then** any stop may be selected (the closest to the incoming point) and the end equals that same stop.
4. **Given** a one-way tour, **When** its nearer endpoint to the incoming point is chosen as start, **Then** the farther endpoint becomes the end.

---

### Edge Cases

- **No warehouse for a driver**: Cannot occur — every driver has exactly one mandatory warehouse (Clarifications). The system MUST prevent a driver from existing without one rather than degrade the projected day; onboarding/assignment enforces the link.
- **A value feeding the day is unknown**: If a travel leg (warehouse↔stop or stop↔stop) cannot be routed, **or** a tour (prior or candidate) has an unknown own duration, that value contributes **0** to a **best-effort** projected day, which is then **flagged as approximate/incomplete** (FR-015) so the manager sees a usable lower bound and knows time may be missing. The routing failure / unknown duration is logged. (The per-leg unknown state is still kept distinct from a genuine zero at the leg level — FR-010.)
- **Single-stop tour**: A tour with only one stop uses that stop as both start and end regardless of loop/one-way, and the connecting drives measure to/from it.
- **Warehouse coincides with a stop**: If the warehouse is at the same location as the chosen start (or end) stop, that leg's travel time is zero — a genuine zero, distinct from unknown.
- **Only one valid start candidate**: If a tour exposes a single valid start (e.g. a one-way tour effectively pinned, or degenerate geometry), that stop is the start with no comparison needed.
- **Tie in travel time between candidates**: If two valid start stops are equidistant from the incoming point, the system picks one deterministically (no error, no prompt).
- **Order of the day's tours**: The between-tour drives follow **assignment order** — each newly assigned tour is appended as the next tour the driver runs (Clarifications). Re-ordering a driver's day is out of scope.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST support **multiple warehouses**, and each driver MUST be associated with **exactly one** warehouse — the location they depart from at the start of the day and return to at the end. A driver without a warehouse MUST NOT be permitted; the link is mandatory and enforced at driver creation/assignment.
- **FR-002**: A driver's projected working time for a date MUST be computed as the full chain: travel from the warehouse to the first tour's start stop, plus each tour's own duration, plus the travel between each tour's end stop and the next tour's start stop, plus the travel from the last tour's end stop back to the warehouse.
- **FR-003**: This chained projected time MUST replace the feature-012 "sum of tour durations" figure wherever a driver's projected day is shown for the assignment decision.
- **FR-004**: Assigning a tour MUST record a **start stop** and an **end stop** for that tour.
- **FR-005**: For a **looping** tour, any stop MUST be eligible as the start/end, and the recorded start stop and end stop MUST be the same stop.
- **FR-006**: For a **one-way** tour, only the two endpoints of the trip MUST be eligible as start/end; an interior stop MUST NOT be selected as either. Selecting one endpoint as the start MUST make the other endpoint the end.
- **FR-007**: The system MUST select the start stop automatically as the valid stop with the shortest travel time from the incoming point — the warehouse for the first tour of the day, or the previous tour's end stop for each subsequent tour — with no manual stop choice required.
- **FR-008**: Travel times for the warehouse legs and the between-tour legs MUST be measured with the same road-accurate routing basis used for tour travel (feature 002), so connecting drives and in-tour travel are comparable.
- **FR-009**: When **any value feeding the projected day is unknown** — a connecting travel leg that cannot be routed (routing error) **or** a tour whose own duration is unknown (a null tour duration, e.g. a 2-point tour with no resolved road time) — the system MUST log it and MUST treat that value as **0 in a best-effort projected day** rather than hiding the whole figure. It MUST NOT silently present the reduced total as exact — see FR-015. (This supersedes the feature-012 approach of propagating unknown to the whole projected figure; the per-leg unknown/zero distinction of FR-010 still holds at the leg level.)
- **FR-010**: A travel leg whose two points are at the same location MUST yield a genuine **zero** duration, kept distinct from the unknown case in FR-009.
- **FR-011**: The projected day MUST be shown in the same human-readable hours/minutes format as the existing tour-duration and projected-hours figures.
- **FR-012**: The chosen **start and end stops** MUST be recorded with the assignment. The connecting travel legs are **not** stored; they are recomputed deterministically from the recorded stops each time the projected day is needed (so a later re-ordering of a driver's tours stays correct), yielding a figure consistent with what was shown at assignment time.
- **FR-013**: The order of a driver's tours for the date MUST follow **assignment order** — each newly assigned tour is appended as the next tour after the driver's existing tours for that date. Manual re-ordering of a driver's day is out of scope.
- **FR-014**: When building the driver list, the many travel-time lookups (up to *drivers × valid-start candidates*, plus chain legs) MUST be executed efficiently: the system MUST (a) request each **distinct** travel leg at most once (identical warehouse/return/between legs shared across drivers are not re-requested), and (b) fetch the outstanding legs with **bounded concurrency** — a capped parallel batch that speeds the response without flooding or tripping the external routing API's rate limits. The chain math per driver then reads pre-fetched durations.
- **FR-015**: When **any** value feeding a driver's projected day is unknown (a failed connecting leg **or** a tour — prior or candidate — with an unknown own duration, per FR-009), the system MUST mark that driver's projected figure as **approximate/incomplete** and surface the mark to the manager (a clear visual indicator alongside the figure). A driver whose every leg routed **and** every tour has a known duration MUST NOT be flagged. The flag conveys "at least this long, possibly more" — it never blocks or alters the assignment itself.
- **FR-016**: The projected-day total MUST be computable from a driver's **resolved tour segments** alone — each segment being a recorded start coordinate, end coordinate, and own duration — plus the warehouse, **without** requiring a prospective/incoming tour or any start-stop selection. Start-stop selection (choosing the nearest valid start for a newly placed tour) MUST be a **separate** step performed before the total is computed. This keeps the total-day computation reusable for later views that simply display all tours already assigned to a driver (no incoming tour to place).

### Key Entities *(include if data involved)*

- **Warehouse**: a fixed location a driver leaves from and returns to each day; the origin of the first connecting drive and the destination of the last. Multiple warehouses exist; a driver references exactly one.
- **Driver**: gains a mandatory associated warehouse (exactly one) and a **chained projected day** for a date — warehouse→first-start travel + Σ tour durations + Σ between-tour travel + last-end→warehouse travel — superseding the plain-sum projection of feature 012.
- **Tour**: gains a designated **start stop** and **end stop**. Looping tours: start = end, any stop eligible. One-way tours: start and end are the two endpoints, interior stops ineligible. These anchor the travel-to/from/between measurements.
- **Travel leg**: a connecting drive whose duration feeds the projected day — warehouse→first start, end→next start between successive tours, and last end→warehouse. Its duration may be **unknown** when routing fails; an unknown leg contributes 0 to a best-effort projected day and flags that day as approximate (FR-009/FR-015).
- **Projected day accuracy flag**: a per-driver marker set when **any** value feeding that driver's projected figure is unknown — a failed connecting leg **or** a tour (prior or candidate) with unknown own duration — telling the manager the figure is a lower bound that may understate the real day.
- **Resolved tour segment**: a tour reduced to what the day-total needs — a start coordinate, an end coordinate, and its own duration (nullable = unknown). The projected day is a pure function of the warehouse plus an ordered list of these; assembling them (and, for a new tour, selecting its start) happens beforehand (FR-016).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For any driver with a warehouse and at least one tour on the date, the projected day equals warehouse→first-start + Σ tour durations + Σ between-tour travel + last-end→warehouse, in 100% of cases.
- **SC-002**: The projected day is never less than the plain sum of the tours' durations; it is strictly greater whenever any connecting drive has a non-zero duration.
- **SC-003**: Every assigned tour has a recorded start stop and end stop consistent with its type — equal stops for looping tours, opposite endpoints for one-way tours, never an interior stop for one-way — in 100% of cases.
- **SC-004**: The chosen start stop is the valid stop with the shortest travel time from the incoming point in 100% of cases (verifiable against the per-candidate travel times).
- **SC-005**: Whenever any value feeding a driver's projected day is unknown (a failed connecting leg **or** a tour with unknown own duration), that driver's figure is shown as a best-effort lower bound **and** carries the approximate/incomplete flag — in 100% of such cases; a driver with all legs routed and all tour durations known is never flagged.
- **SC-006**: No distinct travel leg is requested from the routing API more than once per driver-list load (verifiable by counting the faked routing calls = the distinct-leg count), and the legs are fetched in **bounded batches of at most the configured cap** (verifiable by asserting each concurrent pool batch is issued with ≤ cap requests, i.e. the distinct set is chunked into ⌈distinct/cap⌉ batches).

## Assumptions

- **Builds on 012**: the driver list, the per-driver projected-hours display, and the assignment flow are those established in features 006/011/012; this feature only redefines *how* the projected figure is computed (adding travel) and adds start/end stop recording.
- **Routing basis**: connecting drives reuse the road-accurate routing introduced in feature 002; "closest" and "travel time" mean routed drive time, not straight-line distance, unless routing is unavailable (then the leg is unknown per FR-009).
- **Endpoints of a one-way tour**: the "two ends" of a one-way trip are its first and last stops in the trip's ordering; interior stops are never start/end candidates.
- **Looping tour eligibility**: because a loop returns to its origin, every stop is a legitimate place to enter and leave, so all stops are start candidates and the end is that same stop.
- **Daily scope**: as in feature 012, "day" is scoped to the tour's selected date; the chain covers that date's tours for the driver plus the candidate tour under consideration.
- **Deterministic tie-break**: when two candidates have equal travel time, selection is deterministic (implementation may pick the first encountered); no user prompt is introduced.
- **Warehouse management scope**: multiple warehouses are supported and each driver is linked to exactly one (mandatory); this feature includes the minimum needed to define warehouse locations and associate one with every driver. Richer warehouse-management UX (bulk editing, address geocoding niceties) beyond that minimum is not required here.
- **Tour ordering is assignment order**: the day's chain appends each newly assigned tour as the next tour; a UI to re-order a driver's tours is explicitly out of scope for this feature.
