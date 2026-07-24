# Feature Specification: Dashboard Home Page

**Feature Branch**: `028-dashboard-home-page`

**Created**: 2026-07-24

**Status**: Draft

**Input**: User description: "As a new feature, we are now going to add a dashboard to replace the root welcome index, that for now still is the default one. We will have at the / endpoint a new page that has a title saying \"Dashboard\" up top, and two boxes with one with a map image saying \"New Tour\", and another saying \"Manage drivers\", each respectively linking to the new tour page and the driver list. In addition to this, we will also update the side panel so now we have as a list the two links \"New Tour\" and \"Manage drivers\"."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Land on a dashboard and reach the main tasks (Priority: P1)

A signed-in planner opens the application root and, instead of the generic starter welcome page, sees a dashboard titled "Dashboard" with two clearly labelled entry points — "New Tour" (shown with a map image) and "Manage drivers". Clicking "New Tour" opens the tour-planning page; clicking "Manage drivers" opens the drivers directory.

**Why this priority**: This is the core of the feature — turning the empty root into a purposeful home from which the two primary workflows are one click away. Delivered alone it already gives every user a useful landing page.

**Independent Test**: Sign in and open the root; verify the "Dashboard" title and the two boxes appear, the "New Tour" box shows a map image, and each box navigates to the correct destination (tour page / drivers directory).

**Acceptance Scenarios**:

1. **Given** a signed-in user, **When** they open the application root, **Then** a page titled "Dashboard" is shown with two boxes: "New Tour" (with a map image) and "Manage drivers".
2. **Given** the dashboard is shown, **When** the user activates the "New Tour" box, **Then** they are taken to the new-tour page.
3. **Given** the dashboard is shown, **When** the user activates the "Manage drivers" box, **Then** they are taken to the drivers directory.
4. **Given** a narrow (mobile) screen, **When** the dashboard is shown, **Then** the title and both boxes remain readable and usable without horizontal overflow.

---

### User Story 2 - Navigate from the side panel anywhere in the app (Priority: P2)

From any page, the planner uses the side panel, which now lists two links — "New Tour" and "Manage drivers" — to jump directly to those workflows without returning to the dashboard first.

**Why this priority**: The side panel is present across the app, so persistent links make the two primary destinations reachable from everywhere; it complements Story 1 but the dashboard is usable without it.

**Independent Test**: On any authenticated page, open the side panel and confirm it lists "New Tour" and "Manage drivers"; activate each and confirm it navigates to the tour page and the drivers directory respectively.

**Acceptance Scenarios**:

1. **Given** any authenticated page, **When** the user looks at the side panel, **Then** it shows a list containing "New Tour" and "Manage drivers".
2. **Given** the side panel, **When** the user activates "New Tour", **Then** they are taken to the new-tour page.
3. **Given** the side panel, **When** the user activates "Manage drivers", **Then** they are taken to the drivers directory.

---

### Edge Cases

- **Root already had a welcome page**: the dashboard takes over the root for signed-in users; the existing sign-in/welcome path for visitors who are not signed in is unchanged.
- **Missing map image**: if the map image for the "New Tour" box cannot be shown, the box still renders with its "New Tour" label and remains clickable (no broken-image gap that hides the action).
- **Narrow screens**: the two boxes stack and the side-panel links remain reachable, with no horizontal overflow.
- **Keyboard & assistive tech**: each box and each side-panel link is reachable and activatable by keyboard, and exposes its label to assistive technology.
- **Current destination**: the side panel indicates which of its links corresponds to the page currently being viewed.

## Requirements *(mandatory)*

### Functional Requirements

#### Dashboard page

- **FR-001**: The application MUST serve a dashboard page at the application root (`/`) for authenticated users, replacing the previous default welcome page as the root landing.
- **FR-002**: The dashboard MUST display the title "Dashboard" at the top of the page.
- **FR-003**: The dashboard MUST present two boxes: one labelled "New Tour" and one labelled "Manage drivers".
- **FR-004**: The "New Tour" box MUST include a map image; the "Manage drivers" box MUST show its label.
- **FR-005**: Activating the "New Tour" box MUST navigate to the new-tour page.
- **FR-006**: Activating the "Manage drivers" box MUST navigate to the drivers directory page.
- **FR-007**: The dashboard MUST remain usable and readable on screens from mobile to desktop width, with the two boxes adapting (stacking as needed) and no horizontal overflow.

#### Side panel

- **FR-008**: The side panel MUST present a list containing two links: "New Tour" and "Manage drivers".
- **FR-009**: The "New Tour" side-panel link MUST navigate to the new-tour page, and the "Manage drivers" side-panel link MUST navigate to the drivers directory page.
- **FR-010**: The side-panel links MUST be available on the authenticated pages of the application that display the side panel.

#### Presentation & robustness

- **FR-011**: The dashboard boxes and side-panel links MUST be operable by keyboard and expose their labels to assistive technology.
- **FR-012**: If the "New Tour" map image cannot be displayed, the box MUST still render its label and remain activatable.
- **FR-013**: The side panel MUST indicate which link, if any, corresponds to the page currently being viewed.
- **FR-014**: All colours used by the dashboard and the new side-panel links MUST come from the project's role-named palette, with no one-off colour values.

### Key Entities *(include if data involved)*

- **Dashboard entry (box)**: a titled, clickable card on the dashboard — a label ("New Tour" / "Manage drivers"), an optional illustrative image (the map for "New Tour"), and the destination it links to.
- **Navigation link**: a named side-panel item — a label and its destination — for "New Tour" and "Manage drivers".

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A signed-in user opening the root sees the "Dashboard" title and both boxes without any further action.
- **SC-002**: From the dashboard, a user reaches the new-tour page in one click via the "New Tour" box and the drivers directory in one click via the "Manage drivers" box.
- **SC-003**: From any authenticated page, a user reaches the new-tour page and the drivers directory in one click via the side-panel links.
- **SC-004**: The dashboard renders without horizontal overflow at viewport widths from 320 px to 2560 px.
- **SC-005**: Every dashboard box and side-panel link is reachable and activatable using only the keyboard.
- **SC-006**: 100% of dashboard boxes and side-panel links navigate to their stated destination.

## Assumptions

- "Replace the root welcome index" means the dashboard becomes the root (`/`) landing for authenticated users; the existing behaviour for users who are not signed in (welcome/sign-in) is left unchanged and out of scope here.
- "The new tour page" is the existing tour-planning page; "the driver list" / "Manage drivers" is the drivers directory added in feature 027 (`/driver`).
- The dashboard is a launcher only: the two boxes and the side-panel links are navigation entry points, with no data entry, statistics, or widgets on the dashboard in this feature.
- The map image on the "New Tour" box is a static, illustrative image (a decorative representation of a map), not a live/interactive map.
- The side panel already exists across authenticated pages; this feature adds the two links to it and does not otherwise redesign it.
- Titles and link/box labels use the exact wording given: "Dashboard", "New Tour", "Manage drivers".
