# Feature Specification: Tour Date Selection

**Feature Branch**: `009-tour-date-selection`

**Created**: 2026-07-01

**Status**: Draft

**Input**: User description: "As a new feature, we should now be able to select the date corresponding to the tour. On the tour creation menu (the map where we place the coordinates), next to the existing options there will be also a Date option to select when that tour is selected for. On loading the page it will default to the current date, and when having done a tour, it will remain on the selected option while the page is loaded when doing new tours. This is a purely date field, the hours are not selected (these will be automatically deduced later depending on driver's schedules)."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Assign a date to a tour (Priority: P1)

A dispatcher planning deliveries opens the tour creation menu, places delivery
stops on the map, and picks the calendar date the tour is intended for before
generating the tour. The selected date becomes part of that tour so the tour is
associated with the day it will be run.

**Why this priority**: The whole feature exists to attach a target day to a
tour. Without it the tour has no scheduled date; every other behavior (default,
persistence) is a refinement of this core capability.

**Independent Test**: Open the tour creation menu, choose a date in the Date
option, create a tour, and confirm the created tour carries the chosen date.

**Acceptance Scenarios**:

1. **Given** the tour creation menu is open with delivery stops placed, **When** the user selects a specific calendar date in the Date option and creates the tour, **Then** the tour is associated with that selected date.
2. **Given** the Date option is visible, **When** the user opens it, **Then** they can pick a day only (no hour/minute/time-of-day selection is offered).

---

### User Story 2 - Sensible default date on page load (Priority: P2)

When the dispatcher first loads the page, the Date option already shows the
current date so they can create a tour for "today" without any extra step.

**Why this priority**: Reduces friction for the most common case (planning for
the current day) but depends on the Date option existing (P1).

**Independent Test**: Load the page fresh and confirm the Date option displays
the current date without the user touching it.

**Acceptance Scenarios**:

1. **Given** a fresh page load, **When** the tour creation menu is displayed, **Then** the Date option shows the current date as its value.
2. **Given** a fresh page load with the default date, **When** the user creates a tour without changing the date, **Then** the tour is associated with the current date.

---

### User Story 3 - Selected date persists across successive tours (Priority: P3)

After a dispatcher sets a date and creates a tour, the Date option keeps showing
that same date so they can create additional tours for the same day without
re-selecting it, for as long as the page stays loaded.

**Why this priority**: A convenience for batch planning multiple tours for one
day. Valuable but secondary to being able to set the date at all and to the
default.

**Independent Test**: Change the date to a non-default day, create a tour, then
begin a new tour and confirm the Date option still shows the previously chosen
date.

**Acceptance Scenarios**:

1. **Given** the user has selected a date different from the default and created a tour, **When** they start creating another tour on the same loaded page, **Then** the Date option still shows the previously selected date.
2. **Given** the user changes the selected date again, **When** they create further tours, **Then** each new tour uses the currently selected date and that date remains shown afterward.
3. **Given** the user reloads the page, **When** the tour creation menu is displayed, **Then** the Date option resets to the current date (persistence lasts only while the page remains loaded).

---

### Edge Cases

- What is shown when the local calendar rolls over to a new day while the page stays loaded? The Date option keeps whatever value it currently holds (default was captured at load time; it is not silently advanced).
- How does the system treat a date in the past or far in the future? Any valid calendar date can be selected; the field does not restrict the range (scheduling feasibility is handled later by driver assignment).
- What happens if the date value is cleared or invalid? The system prevents creating a tour without a valid date, keeping the last valid date rather than producing a tour with no date.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The tour creation menu MUST present a Date option positioned alongside the existing tour options.
- **FR-002**: The Date option MUST allow the user to select a calendar day only, with no selection of hours, minutes, or time-of-day.
- **FR-003**: On page load, the Date option MUST default to the current date.
- **FR-004**: When a tour is created, the tour MUST be associated with the date currently shown in the Date option.
- **FR-005**: After a tour is created, the Date option MUST retain the currently selected date for use by subsequent tour creations, for as long as the page remains loaded.
- **FR-006**: The selected date MUST persist only within the current page session; a page reload MUST reset the Date option to the current date.
- **FR-007**: The system MUST NOT allow a tour to be created without a valid date value in the Date option.
- **FR-008**: The Date option MUST accept any valid calendar date without restricting past or future ranges.

### Key Entities *(include if feature involves data)*

- **Tour**: A planned set of delivery stops. Gains a new attribute — the target **date** (day only, no time) on which the tour is intended to be run.
- **Selected Date (session state)**: The date currently chosen in the tour creation menu; initialized to the current date at load and retained across successive tour creations until the page is reloaded.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On a fresh page load, 100% of the time the Date option shows the current date without user interaction.
- **SC-002**: Every tour created carries a date, and that date matches the value shown in the Date option at creation time in 100% of cases.
- **SC-003**: When creating multiple tours in one page session, the user sets the date at most once per intended day (no re-selection required between tours for the same day).
- **SC-004**: A user can assign a date to a tour in a single interaction (one date pick) without any additional steps.

## Assumptions

- The Date option lives in the same tour creation menu as the existing options (delivery mode, loop toggle, driver assignment, stop duration) and follows the same visual and interaction conventions.
- "While the page is loaded" means the persistence is in-memory session state, not stored to a backend or browser storage; a full page reload resets it to the current date.
- The current date is determined from the user's local calendar at the moment the page loads.
- No range restriction (past/future) is imposed at this stage; feasibility against driver schedules is deferred to later features.
- Time-of-day is intentionally out of scope and will be derived later from driver schedules, per the feature description.
