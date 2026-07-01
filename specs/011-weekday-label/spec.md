# Feature Specification: Selected Weekday Label

**Feature Branch**: `011-weekday-label`

**Created**: 2026-07-01

**Status**: Draft

**Input**: User description: "We will also in the presentation phase add a small text next to the date showing what day of the week is currently selected, that will of course also update when changing the date."

## Context

On the presentation page the tour's date field is already visible and editable
(features 009 and 010), and the driver list filters by the selected date's
weekday (feature 010). This feature adds a small textual label next to the date
that names the weekday of the currently selected date (e.g. "Wednesday"), giving
the user an at-a-glance confirmation of which day they are targeting. The label
updates whenever the date changes.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See the weekday of the selected date (Priority: P1)

On the presentation page, next to the date field, the user sees a small text
that spells out the day of the week the selected date falls on, so they can
confirm at a glance whether they are targeting a weekday or weekend without
mentally computing it.

**Why this priority**: This is the entire feature — surfacing the weekday name
beside the date. Without it there is nothing to deliver.

**Independent Test**: Load the presentation page for a tour whose date is a known
weekday and confirm the label next to the date shows that weekday's name.

**Acceptance Scenarios**:

1. **Given** the presentation page with a selected date that falls on a Wednesday, **When** the page is displayed, **Then** a small text next to the date reads the weekday name for Wednesday.
2. **Given** the date field and its weekday label, **When** the page renders, **Then** the label appears adjacent to (next to) the date field.

---

### User Story 2 - Label updates when the date changes (Priority: P1)

When the user changes the date on the presentation page, the weekday label
immediately updates to reflect the newly selected date's day of the week.

**Why this priority**: A weekday label that does not track the date would be
misleading; keeping it in sync is essential to the feature's value.

**Independent Test**: Change the date to a day that falls on a different weekday
and confirm the label updates to the new weekday name.

**Acceptance Scenarios**:

1. **Given** the weekday label showing the current selection, **When** the user changes the date to one falling on a different weekday, **Then** the label updates to the new weekday name.
2. **Given** the user changes the date to another day within the same weekday name (e.g. next week's same weekday), **When** the label updates, **Then** it still shows that weekday name (no error, correct value).

---

### Edge Cases

- What is shown before any date is selected? The date always has a value (defaults to the current date per feature 009), so the label always has a weekday to show and is never blank.
- How is the weekday determined? It is derived from the selected calendar date, consistent with the schedule-filtering weekday derivation (feature 010), so the label and the driver filtering always agree on the weekday.
- Does the label change if the real-world day rolls over while the page stays open? The label reflects the selected date's weekday, not the current day, so it only changes when the selected date changes.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The presentation page MUST display a small text label, positioned next to the date field, showing the name of the weekday that the selected date falls on.
- **FR-002**: The weekday name MUST be derived from the currently selected date, consistent with the weekday used for driver schedule filtering (feature 010).
- **FR-003**: When the selected date changes, the weekday label MUST update to the weekday of the new date.
- **FR-004**: The weekday label MUST always show a value whenever a date is selected (never blank), since the date always has a value.

### Key Entities *(include if feature involves data)*

- **Weekday label**: a derived, read-only text showing the day-of-week name of the selected date; it holds no independent state and is computed from the selected date.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For any selected date, the label shows the correct weekday name 100% of the time.
- **SC-002**: Changing the date updates the label to the correct weekday in every case, with no stale value remaining.
- **SC-003**: The label is visible next to the date field on 100% of presentation-page views.
- **SC-004**: The weekday shown by the label always matches the weekday used to filter the driver list (no disagreement between the two).

## Assumptions

- **Builds on features 009 and 010**: the date field and its presentation-page placement/editability already exist; this feature only adds a derived weekday label beside it.
- **Read-only label**: the label is purely informational; it is not an input and cannot be edited directly.
- **Weekday naming**: the weekday is shown as its day-of-week name (e.g. "Monday"), presented in the application's existing language/locale convention; no custom formatting beyond the day name is required.
- **Derived from selected date**: the label reflects the selected date's weekday, not the real-world current day; it changes only when the selected date changes.
