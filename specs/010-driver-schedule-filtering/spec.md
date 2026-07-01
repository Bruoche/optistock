# Feature Specification: Driver Schedule Filtering

**Feature Branch**: `010-driver-schedule-filtering`

**Created**: 2026-07-01

**Status**: Draft

**Input**: User description: "The date field should still appear and be modifiable during the presentation phase. Drivers will all have a "schedule" that defines what days of the week they are allowed to work at (for example monday to friday, or week-end only, or a 4 day week or any other combination of days). Only drivers that are authorized to work on the selected day will be presented to the user in the list. Everytime the date is changed the list is refreshed to make sure we only get available drivers for the selected date."

## Context

The tour flow already assigns a **date** to a tour on the creation menu (feature
009) and, after optimizing, lists the **available drivers** on the results /
presentation page filtered by the tour's delivery mode (feature 006). This
feature extends both: the date field remains visible and editable on the
presentation page, and every driver now carries a **weekly schedule** — the set
of weekdays they are permitted to work. The driver list on the presentation page
is further narrowed to only drivers whose schedule includes the weekday of the
selected date, and it re-filters live whenever the date is changed.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Only drivers who work the selected day are listed (Priority: P1)

On the presentation page, after a tour is optimized, the user sees only drivers
who both support the tour's delivery mode and are scheduled to work on the
weekday of the tour's selected date. Drivers who are off on that weekday do not
appear.

**Why this priority**: This is the core deliverable — matching drivers to the
day they can actually work. Without it the list would offer drivers who are
unavailable on the chosen date.

**Independent Test**: Set a tour date that falls on a given weekday, optimize,
and confirm the list contains exactly the mode-matching drivers whose schedule
includes that weekday and excludes those whose schedule does not.

**Acceptance Scenarios**:

1. **Given** an optimized tour whose selected date is a Wednesday, **When** the presentation page lists drivers, **Then** only drivers whose schedule includes Wednesday (and who support the tour's mode) appear.
2. **Given** a driver scheduled Monday–Friday, **When** the selected date is a Saturday, **Then** that driver is not listed.
3. **Given** a driver scheduled weekends only, **When** the selected date is a Sunday, **Then** that driver is listed (assuming their mode matches).
4. **Given** no driver both supports the tour's mode and works the selected weekday, **When** the list would render, **Then** the "no available driver" message is shown in place of the list.

---

### User Story 2 - Date is visible and editable on the presentation page (Priority: P1)

The date field that was set on the creation menu still appears on the
presentation page and can be changed there, so the user can retarget the tour to
a different day without going back.

**Why this priority**: The re-filtering behavior depends on being able to change
the date on the presentation page; the field must be present and editable for
the feature to function.

**Independent Test**: Optimize a tour, view the presentation page, confirm the
date field shows the tour's current date and can be edited in place.

**Acceptance Scenarios**:

1. **Given** an optimized tour, **When** the presentation page is displayed, **Then** the date field is visible and shows the tour's currently selected date.
2. **Given** the presentation page, **When** the user opens the date field, **Then** they can pick a different calendar day (day only, no time).

---

### User Story 3 - List refreshes every time the date changes (Priority: P2)

Whenever the user changes the date on the presentation page, the driver list
immediately refreshes so it always reflects the drivers available for the
newly selected date.

**Why this priority**: Keeps the list correct as the user explores different
days; depends on stories 1 and 2 being in place.

**Independent Test**: Change the date to another weekday and confirm the driver
list updates to the set valid for the new day without a manual refresh.

**Acceptance Scenarios**:

1. **Given** a driver list shown for the current date, **When** the user changes the date to a weekday with a different set of scheduled drivers, **Then** the list refreshes to that new set.
2. **Given** the date is changed to a weekday no eligible driver works, **When** the list refreshes, **Then** the "no available driver" message replaces the list.
3. **Given** the date is changed back to the original weekday, **When** the list refreshes, **Then** the original eligible set is shown again.

---

### Edge Cases

- What if a driver's schedule is empty (works no day)? They never appear for any selected date.
- What if a driver works every day of the week? They appear for any selected date, subject to the mode match.
- What happens when the selected date changes the weekday but the eligible set is identical? The list re-evaluates and shows the same entries (no visible change, no error).
- How is the weekday of the selected date determined across boundaries? The weekday is derived from the selected calendar date itself, independent of the current day.
- What if changing the date empties the list? The "no available driver" message is shown in place of the list (consistent with feature 006's empty-list behavior).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Each driver MUST have a schedule expressed as the set of weekdays (any combination of the seven days) on which they are permitted to work.
- **FR-002**: The presentation page MUST display the tour's date field, showing the currently selected date.
- **FR-003**: The date field on the presentation page MUST be modifiable, allowing selection of a different calendar day (day only, no time-of-day).
- **FR-004**: The available-driver list MUST include only drivers whose schedule includes the weekday of the selected date, in addition to the existing delivery-mode match.
- **FR-005**: The system MUST derive the applicable weekday from the selected calendar date to evaluate driver schedules.
- **FR-006**: Every time the selected date changes, the system MUST refresh the available-driver list to reflect the drivers valid for the new date.
- **FR-007**: When no driver both supports the tour's mode and is scheduled for the selected weekday, the system MUST show the existing "no available driver" message in place of the list.
- **FR-008**: A schedule MUST support arbitrary weekday combinations (e.g., Monday–Friday, weekends only, a four-day week, or any other subset).
- **FR-009**: Changing the date on the presentation page MUST keep the rest of the optimized tour intact (only the driver list is re-filtered).

### Key Entities *(include if feature involves data)*

- **Driver**: extends the existing driver (feature 006) with a **schedule** — the set of weekdays the driver is allowed to work.
- **Schedule**: the set of permitted weekdays for a driver; any subset of the seven days, including empty or all.
- **Available-driver list**: recomputed for the selected date as the drivers whose supported modes include the tour's mode AND whose schedule includes the selected date's weekday.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For any selected date, the list contains 100% of drivers who both match the tour's mode and are scheduled for that date's weekday, and 0% of drivers who fail either condition.
- **SC-002**: Changing the date updates the list to the correct set in every case, with no stale entries remaining from the previous date.
- **SC-003**: The date field is present and editable on the presentation page in 100% of optimized-tour views.
- **SC-004**: When the eligible set is empty for a selected date, the "no available driver" message is shown 100% of the time (never a blank or broken list).
- **SC-005**: A user can retarget a tour to a different day and see the corresponding drivers in a single interaction (one date change), with no manual refresh.

## Assumptions

- **Builds on features 006 and 009**: driver listing with mode filtering (006) and tour date selection (009) already exist; this feature layers schedule-based filtering and moves/keeps the date field on the presentation page.
- **Schedule is weekday-based**: schedules are defined by days of the week, not specific calendar dates; there are no per-date exceptions, holidays, or vacation overrides in this feature's scope.
- **Driver data already exists**: driver schedules are pre-existing/seeded data alongside their name, image, and modes; a management UI to edit schedules is out of scope.
- **Combined filter is AND**: a driver is eligible only if they match both the tour's delivery mode and the selected weekday.
- **Empty-list message reused**: the "no available driver" message is the same one introduced in feature 006 ("No one available for this delivery.").
- **Date remains day-only**: consistent with feature 009, the field selects a calendar day with no time-of-day; hours are still deferred to later scheduling.
- **Weekday derivation is local**: the weekday used for filtering is derived from the selected calendar date as interpreted in the user's local calendar.
