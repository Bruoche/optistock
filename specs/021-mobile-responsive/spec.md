# Feature Specification: Mobile Responsive Interface

**Feature Branch**: `021-mobile-responsive`

**Created**: 2026-07-05

**Status**: Draft

**Input**: User description: "We now need to make the application responsive, so it can be used on mobile too. We will add a mobile mode for the interface that will make the main bar able to be scrolled through if they overflow out of the screen on both menus."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Reach every control on a narrow screen via a scrollable bar (Priority: P1)

A planner opens the tour optimization screen on a phone. The primary horizontal bar at the top of the working area — the editing-view control bar (mode, loop, date, Optimize) and, after optimizing, the result-view bar (the workday figures plus New / Edit / Assign) — is wider than the phone screen. Instead of controls being clipped off-screen and unreachable, the bar itself scrolls sideways within its own strip, so the planner can swipe across it and use every control, while the rest of the page stays put.

**Why this priority**: This is the exact mechanism the feature calls for and the difference between "unusable on mobile" (controls permanently off-screen) and "usable on mobile". Without it, core actions like Optimize or Assign cannot be reached on a phone.

**Independent Test**: Load the tour screen at a phone width, confirm the top bar's contents overflow, and verify the bar scrolls horizontally to expose and operate every control — in both the editing and the result view.

**Acceptance Scenarios**:

1. **Given** the editing view on a viewport narrower than its control bar, **When** the planner swipes the bar sideways, **Then** the mode, loop, date, and Optimize controls all become reachable and operable.
2. **Given** the result view on a narrow viewport, **When** the planner swipes the bar sideways, **Then** the workday figures and the New, Edit, and Assign actions all become reachable and operable.
3. **Given** a viewport wide enough to fit a bar's contents, **When** the bar is shown, **Then** it does not scroll and looks as it does today.
4. **Given** either bar is scrolled sideways, **When** the planner interacts with the page, **Then** the page as a whole does not scroll horizontally — only the bar's own strip does.

---

### User Story 2 - Use the tour screen without a broken layout on mobile (Priority: P2)

A planner uses the tour optimization screen on a phone in portrait. The map, the stop list / result area, and the bars fit the screen width without the whole page overflowing sideways or critical UI being cut off, so the screen is workable end-to-end on a small device.

**Why this priority**: The scrollable bars (US1) solve the controls, but the surrounding screen must also hold together at mobile widths for the feature to deliver "usable on mobile". Still, US1 is the headline mechanism and can ship first.

**Independent Test**: Load the tour screen at a phone width and confirm no whole-page horizontal scrollbar appears and the map + content areas remain readable and interactive.

**Acceptance Scenarios**:

1. **Given** the tour screen on a phone-width viewport, **When** it loads, **Then** the page body does not scroll horizontally.
2. **Given** the tour screen on a phone-width viewport, **When** the planner views it, **Then** the map and the stop-list / result area are both usable (visible, scrollable where appropriate, and interactive).

---

### Edge Cases

- **Extremely narrow screens (~320px)**: the bars still scroll rather than clipping any control; no control becomes permanently unreachable.
- **A bar that exactly fits**: no scroll strip or overflow affordance appears; behavior matches the wide-screen look.
- **Rotation portrait↔landscape**: as available width changes, a bar starts or stops scrolling accordingly without losing access to any control.
- **Open dropdowns / pickers (mode select, date picker)**: opening a control inside a scrollable bar still presents its menu usably on a small screen.
- **Desktop / wide screens**: unchanged — no scroll strip, same layout as before.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: When a main bar's contents are wider than the available viewport, that bar MUST scroll horizontally within its own strip so every control it holds remains reachable.
- **FR-002**: This scroll-on-overflow behavior MUST apply to both main bars: the editing-view control bar and the result-view summary/action bar.
- **FR-003**: When a bar's contents fit the available width, it MUST NOT show a scroll strip or overflow affordance, and MUST match the current wide-screen appearance.
- **FR-004**: Scrolling a bar MUST NOT cause the page as a whole to scroll horizontally; only the bar's own strip scrolls.
- **FR-005**: Every interactive control in both bars (mode, loop, date, Optimize; the workday figures, New, Edit, Assign) MUST remain fully operable via touch at mobile widths.
- **FR-006**: The tour optimization screen MUST remain usable at mobile widths: the map and the stop-list / result area stay visible, interactive, and free of a whole-page horizontal overflow.
- **FR-007**: Responsive behavior MUST adapt automatically to the viewport size, with no manual "switch to mobile" action required from the user.

### Key Entities

- Not applicable — this feature changes presentation/layout only; it introduces no new data.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On a 360px-wide viewport, a user can reach and activate 100% of the controls in both the editing-view and result-view bars.
- **SC-002**: At any width down to 320px, no control in either bar is permanently clipped or unreachable.
- **SC-003**: On phone-width viewports, the tour screen's page body never scrolls horizontally.
- **SC-004**: On wide (desktop) viewports, the layout and bar appearance are unchanged from before the feature.
- **SC-005**: A first-time mobile user can complete the core flow (place stops → Optimize → reach Assign) on a phone without pinch-zooming to hit a control.

## Assumptions

- Responsiveness is automatic and viewport-driven; "mobile mode" is a layout adaptation that engages by screen size, not a user-toggled setting.
- The two "menus" / "main bars" are the tour optimization screen's two states: the editing-view control bar and the result-view summary/action bar. These are the feature's primary screens and the focus of this work.
- Horizontal scrolling within an overflowing bar (rather than wrapping or stacking its controls) is the chosen overflow strategy, per the feature description.
- The vendored application shell already handled elsewhere (top header, collapsible sidebar, authentication screens) is already responsive and is out of scope here; this feature targets the tour optimization feature screens.
- "Mobile" refers to small viewports (phones); behavior is continuous — a bar scrolls whenever its contents overflow the available width, at any screen size — rather than gated to a single hard breakpoint.
- Standard touch scrolling (swipe/drag with momentum) is sufficient; no custom scroll buttons or arrows are required.
