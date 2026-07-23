# Feature Specification: Drivers Directory

**Feature Branch**: `027-driver-directory`

**Created**: 2026-07-24

**Status**: Draft

**Input**: User description: "Add a new page to see all drivers, so we can access manageable drivers. A search-criteria bar at the top: a name search (partial matches, case-insensitive — empty shows all, 'cha' matches 'Sacha Brook', 'Charline Klein', 'Hector Chard'…), a required-transportation selector (none selected by default → all drivers; if several selected, drivers must have all of them), and an optional warehouse among all available warehouses. Below the bar, the list shows all drivers sorted by name with all their info (icon, name, available transportation modes, warehouse), presented like the list on the tour-assignment page. The drivers are dynamically filtered as any of the three criteria change, with 'no drivers found with current criterias.' shown when none match."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Browse all drivers and open one to manage (Priority: P1)

A planner opens the drivers directory and sees every driver, sorted by name, each showing its picture, name, the delivery modes it can run, and its assigned warehouse — the same row presentation used on the tour-assignment page. Clicking a driver opens that driver's management page.

**Why this priority**: This is the core purpose — a single place to see and reach every manageable driver. Without it the page has no value; with it alone the planner can already find and open any driver.

**Independent Test**: Open the directory with several drivers seeded; verify all appear, sorted by name, each with picture/placeholder, name, mode icons, and warehouse, and that clicking a row navigates to that driver's management page.

**Acceptance Scenarios**:

1. **Given** several drivers exist, **When** the planner opens the directory, **Then** every driver is listed, sorted alphabetically by name, each showing its picture (or a neutral placeholder), name, delivery modes, and warehouse.
2. **Given** the directory is shown, **When** the planner clicks a driver row, **Then** they are taken to that driver's management page.
3. **Given** no drivers exist at all, **When** the planner opens the directory, **Then** an explicit "no drivers found with current criterias." message is shown instead of an empty area.
4. **Given** the list is still loading, **When** the data has not yet arrived, **Then** a loading indicator is shown rather than an empty or misleading list.

---

### User Story 2 - Find drivers by name (Priority: P2)

The planner types into a name search field and the list narrows to drivers whose name contains what they typed, matched case-insensitively and on partial text. Clearing the field shows everyone again.

**Why this priority**: Name search is the most common way to locate a specific driver and is the primary filter of the bar; it builds directly on the list from Story 1.

**Independent Test**: With drivers named "Sacha Brook", "Charline Klein", "Hector Chard", and "Diego Ruiz", type "cha" and verify only the first three show; clear and verify all four return.

**Acceptance Scenarios**:

1. **Given** the full list, **When** the planner types part of a name, **Then** only drivers whose name contains that text (anywhere in the name) remain, and they stay sorted by name.
2. **Given** a search term, **When** it differs only in letter case from a driver's name, **Then** that driver still matches (case-insensitive).
3. **Given** text is in the field, **When** the planner clears it, **Then** the full list returns.
4. **Given** a search term matching nobody, **When** it is applied, **Then** "no drivers found with current criterias." is shown.

---

### User Story 3 - Narrow by required transport modes and warehouse (Priority: P3)

The planner selects one or more required transportation modes and/or a warehouse. The list keeps only drivers that can run **every** selected mode and, if a warehouse is chosen, are assigned to it. With no mode selected and no warehouse chosen, everyone is shown.

**Why this priority**: These structural filters refine the search but are used less often than name lookup; they combine with Stories 1–2.

**Independent Test**: Seed drivers with varied mode sets and warehouses; select two modes and confirm only drivers having both remain; add a warehouse and confirm the result narrows to that warehouse; deselect all and clear the warehouse to confirm the full list returns.

**Acceptance Scenarios**:

1. **Given** the full list and no mode selected, **When** the planner selects one mode, **Then** only drivers that can run that mode remain.
2. **Given** one mode selected, **When** the planner selects a second mode, **Then** only drivers that can run **both** selected modes remain (all selected modes required).
3. **Given** a warehouse chosen, **When** it is applied, **Then** only drivers assigned to that warehouse remain.
4. **Given** name, modes, and warehouse criteria are all set, **When** they are applied together, **Then** only drivers satisfying all three remain.
5. **Given** any combination of criteria, **When** the planner clears them all, **Then** every driver is shown again.

---

### Edge Cases

- **Any criterion change** re-filters the list immediately, without a page reload and without the planner pressing a "search" button.
- **No matches** for the current combination of criteria shows the exact text "no drivers found with current criterias.".
- **Empty name field** contributes no restriction (all drivers pass the name criterion); whitespace-only input is treated the same as empty.
- **No modes selected** contributes no restriction; **no warehouse selected** contributes no restriction.
- **Slow or failed load**: while the drivers are loading a spinner is shown; if they cannot be retrieved an error message is shown rather than a silent empty list.
- **A driver with no picture** shows the neutral placeholder used elsewhere in the product.
- **A driver with several modes** shows all of its mode icons; selecting one required mode still matches it.
- **Rapid criteria changes** (fast typing, toggling modes quickly) always settle on the result for the latest criteria, with no stale or flickering list.
- **Narrow screens**: the criteria bar and the list remain usable and readable on mobile without horizontal overflow.

## Requirements *(mandatory)*

### Functional Requirements

#### Page & list

- **FR-001**: The system MUST provide a drivers-directory page, available to authenticated, verified users, that lists drivers.
- **FR-002**: The list MUST show every driver that matches the current criteria, sorted alphabetically by name.
- **FR-003**: Each driver row MUST show the driver's picture (or a neutral placeholder when none), name, the delivery modes it can run, and its assigned warehouse — presented consistently with the driver list on the tour-assignment page.
- **FR-004**: Clicking a driver row MUST open that driver's management page.
- **FR-005**: When no driver matches the current criteria, the list MUST display the exact text "no drivers found with current criterias.".
- **FR-006**: While the drivers are being loaded the page MUST show a loading indicator; if they cannot be retrieved it MUST show an error message, never a silent empty list.

#### Criteria bar

- **FR-007**: The page MUST present, in a bar above the list, three criteria: a name search field, a required-transportation-modes selector, and an optional warehouse selector.
- **FR-008**: The name search MUST match drivers whose name contains the entered text anywhere within it (partial match), case-insensitively; an empty (or whitespace-only) field MUST impose no name restriction (all drivers pass).
- **FR-009**: The transportation-modes selector MUST allow selecting zero or more modes; with none selected it MUST impose no restriction, and with one or more selected only drivers that can run **all** selected modes MUST remain.
- **FR-010**: The warehouse selector MUST offer all available warehouses plus an "any / none" state; when a warehouse is chosen only drivers assigned to it MUST remain, and when none is chosen it MUST impose no restriction.
- **FR-011**: The three criteria MUST combine conjunctively — a driver is shown only when it satisfies the name, the modes, and the warehouse criteria simultaneously.
- **FR-012**: The list MUST re-filter dynamically as soon as any criterion changes, with no full page reload and no explicit "search" action required.
- **FR-013**: The default state (empty name, no modes selected, no warehouse chosen) MUST show all drivers.

#### Presentation & robustness

- **FR-014**: The page MUST remain usable on mobile as on desktop, with the criteria bar and list adapting without horizontal overflow.
- **FR-015**: Rapid successive criteria changes MUST leave the list showing the result for the latest criteria, with no stale results.
- **FR-016**: All colours used by the page MUST come from the project's role-named palette, with no one-off colour values.

### Key Entities *(include if feature involves data)*

- **Driver**: the person listed — picture, name, the delivery modes they can run, and their assigned warehouse; the subject a row links to for management.
- **Delivery mode**: a way of travelling (walking, driving, trucking) a driver may be qualified for; the options offered by the modes selector.
- **Warehouse**: a named location a driver is assigned to; the options offered by the warehouse selector and the target of the warehouse criterion.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A planner can open the directory and see every driver sorted by name, each with picture, name, modes, and warehouse, without any further action.
- **SC-002**: Typing part of a name narrows the list to exactly the drivers whose name contains that text (case-insensitively) — e.g. "cha" yields "Sacha Brook", "Charline Klein", "Hector Chard" and excludes "Diego Ruiz".
- **SC-003**: Selecting multiple required modes yields only drivers possessing all of them; selecting a warehouse further restricts to that warehouse; clearing all criteria restores the full list.
- **SC-004**: Any criterion change updates the visible list within 300 ms for already-loaded data, with no reload and no "search" button.
- **SC-005**: When nothing matches, the page shows the exact text "no drivers found with current criterias.".
- **SC-006**: Clicking any driver reaches that driver's management page in 100% of rows.
- **SC-007**: The page renders without horizontal overflow at viewport widths from 320 px to 2560 px.

## Assumptions

- "Access manageable drivers" means each row links to the driver's existing management page (feature 025 at `/driver/{id}`); no editing happens inline on the directory.
- Drivers are shared across the product (not scoped per user or team), so the directory lists all drivers; any authenticated, verified user may view it, consistent with the existing driver/tour pages.
- The row presentation reuses the visual language of the tour-assignment driver list (picture/placeholder, name, mode icons, warehouse name); this directory does not show workday/road-time figures — those belong to the assignment context.
- "Required transportations" are the delivery modes a driver supports; the required-mode filter uses AND semantics as stated (a driver must support every selected mode).
- The warehouse criterion filters on the driver's assigned warehouse (each driver has exactly one).
- Sorting is alphabetical by name using the same ordering already used for the available-driver list; matching and sorting are case-insensitive.
- The exact empty-state wording, including its phrasing "criterias", is used verbatim as given.
