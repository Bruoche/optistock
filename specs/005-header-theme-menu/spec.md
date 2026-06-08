# Feature Specification: Header Brand & Theme Menu

**Feature Branch**: `005-header-theme-menu`

**Created**: 2026-06-08

**Status**: Draft

**Input**: User description: "As a new feature, I'd like to set-up the header menu. I'm thinking of replacing the useless starter kit dropdown menu already present on the side, keeping its design that is satisfactory and responsive so we simply have to change its content to ours. As such, we will keep its structure, but replace the text \"Laravel Starter Kit\" by \"Optistock\", and remove all the rest of the content in it under the title. (We can keep the laravel logo as placeholder for now). We will instead add just a button allowing selecting the dark/light theme, with light mode, dark mode or browser (the default, same as is currently I assume)."

## Context

The application still shows the **Laravel starter-kit** branding and navigation in the side menu — a leftover that does not belong to Optistock. This feature reuses that menu's existing, responsive design but swaps its content for ours: the title becomes **Optistock**, the starter-kit links/items beneath it are removed, and the only control kept/added is a **theme selector** (light / dark / browser-default). The app already supports light, dark, and an OS-following ("system") appearance with system as the default; this feature surfaces that choice in the menu rather than introducing a new theming mechanism.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See Optistock branding instead of the starter kit (Priority: P1)

A user opens the side menu and sees "Optistock" as the title, with no leftover Laravel starter-kit links or items beneath it; the menu keeps its current look and responsive behaviour.

**Why this priority**: The starter-kit branding/content is wrong for the product and must go; it's the visible identity of the app.

**Independent Test**: Open the menu and confirm the title reads "Optistock", the logo placeholder is still shown, and none of the previous starter-kit entries remain under the title.

**Acceptance Scenarios**:

1. **Given** the side menu, **when** the user views it, **then** the title reads "Optistock" (not "Laravel Starter Kit") and the logo is still shown as a placeholder.
2. **Given** the side menu, **when** the user views the area under the title, **then** the previous starter-kit content has been removed, leaving only the theme selector.
3. **Given** a narrow / collapsed viewport, **when** the user views the menu, **then** it keeps the same responsive behaviour it had before this change.

---

### User Story 2 - Choose the colour theme (Priority: P1)

From the menu, a user picks the appearance — light, dark, or browser-default — and the app updates immediately and remembers the choice.

**Why this priority**: The theme selector is the one piece of functionality this menu now carries; it's the feature's core deliverable.

**Independent Test**: Pick each of the three options and confirm the app's appearance changes accordingly; reload and confirm the choice is retained; with "browser" selected, confirm the app follows the OS setting.

**Acceptance Scenarios**:

1. **Given** the theme selector, **when** the user picks "light" (or "dark"), **then** the app switches to that appearance immediately.
2. **Given** the user picks "browser", **when** the OS is in dark (or light) mode, **then** the app matches the OS appearance, and follows it if the OS setting later changes.
3. **Given** a chosen option, **when** the user reloads or returns later, **then** the same choice is still in effect.
4. **Given** a user who has never chosen, **when** they first load the app, **then** the appearance defaults to "browser" (follow OS).

---

### Edge Cases

- **No prior choice**: first load defaults to browser/OS appearance.
- **OS theme changes while "browser" is selected**: the app updates live to match.
- **Switching between options repeatedly**: each switch takes effect with no reload needed and the last choice wins.
- **Collapsed menu (icon mode)**: the theme control reduces to a single icon (the current mode's icon), no label; it stays reachable and usable.
- **Mobile menu**: the title + theme control remain reachable and usable.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The side menu MUST display "Optistock" as its title in place of "Laravel Starter Kit".
- **FR-002**: The menu MUST keep its existing structure, styling, and responsive behaviour; only its content changes. The current logo MAY remain as a placeholder.
- **FR-003**: The starter-kit navigation, external links, and team switcher beneath the title MUST be removed. The functional account/authentication user menu (profile/logout) MAY be retained so that capability is not lost and need not be rebuilt later.
- **FR-004**: The menu MUST provide a theme control that can reach exactly three states: **light**, **dark**, and **browser** (follow the operating system). It MAY be a single toggle that cycles through the three states.
- **FR-005**: When the user has made no choice, the theme MUST default to **browser**.
- **FR-006**: Changing the theme MUST apply the corresponding appearance immediately, without a reload.
- **FR-007**: The selected option MUST persist across reloads and return visits.
- **FR-008**: While **browser** is selected, the app MUST follow the OS appearance and update live if the OS setting changes.
- **FR-009**: The theme control MUST clearly indicate the currently active mode — a mode-specific icon, plus a corresponding text label when the menu is expanded.
- **FR-010**: When the menu is collapsed, the theme control MUST reduce to a single icon (its label hidden), preserving the collapsed layout.

### Key Entities *(include if feature involves data)*

- **Theme Preference**: the user's chosen appearance — one of `light`, `dark`, `browser` (default `browser`) — persisted on the user's device.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of menu views show "Optistock" and contain no residual starter-kit links/items beneath the title.
- **SC-002**: Each of the three theme options can be selected and changes the app appearance with no perceptible delay and no reload.
- **SC-003**: A chosen theme is still in effect after a page reload and on a later visit.
- **SC-004**: On a first visit with no stored choice, the app appearance matches the OS setting.
- **SC-005**: The menu remains usable and correctly laid out at the same viewport sizes it supported before the change.

## Assumptions

- The "side menu" is the existing starter-kit brand menu in the sidebar/header; this feature changes only its content, not its structure or styling (per the user's intent to keep the satisfactory, responsive design).
- "Browser" maps to the application's existing **system** appearance (follow OS), which is already the default — so no new theming engine is introduced; the feature surfaces the existing light/dark/system mechanism in the menu.
- The theme preference continues to be stored on the user's device as it is today (no backend/account storage added).
- The Laravel logo is kept as a **placeholder**; designing/replacing it is out of scope for this feature.
- The starter-kit navigation, external repository/documentation links, and team switcher are removed (not needed for Optistock now). The account/authentication user menu (profile, logout) is **kept** because it is functional and removing it would only force re-adding it later.
