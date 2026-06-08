---
description: "Task list for Header Brand & Theme Menu"
---

# Tasks: Header Brand & Theme Menu

**Input**: Design documents from `/specs/005-header-theme-menu/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/header-theme-menu.md

**Tests**: INCLUDED — constitution (I. Quality First) + plan enumerate them.

**Organization**: Grouped by user story. US1 (rebrand + strip) and US2 (theme selector) are both P1.
Frontend-only; the theming engine (`useAppearance`) is reused unchanged.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different files, no incomplete-task dependency.
- **[Story]**: US1 / US2.

## Path Conventions

Front-end: `resources/js/`.

---

## Phase 1: Setup

- [x] T001 Confirm `resources/js/hooks/use-appearance.tsx` is reused unchanged — it is the standard starter-kit theming method (light/dark/system, default system) and already covers FR-005/006/007/008. No edit; this is the basis the selector builds on.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: None. The theming hook already exists; both stories build directly on it. No blocking work.

---

## Phase 3: User Story 1 - See Optistock branding instead of the starter kit (Priority: P1) 🎯 MVP

**Goal**: The sidebar shows the Optistock title (logo placeholder kept) and none of the useless starter-kit
content beneath it (nav/links/team switcher), while keeping the account/auth user menu and the skeleton +
responsiveness.

**Independent Test**: Render the sidebar; confirm "Optistock" title, logo placeholder present, the user
menu still present, and no Dashboard / Repository / Documentation / team switcher entries.

### Tests for User Story 1 ⚠️ (write first, ensure they fail)

- [x] T002 [P] [US1] Create resources/js/components/app-sidebar.test.tsx: renders "Optistock"; **keeps** the user menu (`NavUser` — assert via its `data-test="sidebar-menu-button"`); does NOT render the removed entries (Dashboard, Repository, Documentation, team-switcher trigger `data-test="team-switcher-trigger"`). Mock `@inertiajs/react` `usePage` (auth user + currentTeam/teams) and `@/hooks/use-appearance`

### Implementation for User Story 1

- [x] T003 [P] [US1] Change the title text "Laravel Starter Kit" → "Optistock" (keep `AppLogoIcon` placeholder) in resources/js/components/app-logo.tsx
- [x] T004 [US1] Remove `TeamSwitcher`, `NavMain`, `NavFooter` (and their now-unused imports + the `mainNavItems`/`footerNavItems` arrays) while keeping the `Sidebar` / `SidebarHeader` / `SidebarContent` / `SidebarFooter` skeleton, the brand row, and **`NavUser`** (functional account/auth menu — kept per user decision), in resources/js/components/app-sidebar.tsx

**Checkpoint**: Sidebar is rebranded + stripped. MVP demoable.

---

## Phase 4: User Story 2 - Choose the colour theme (Priority: P1)

**Goal**: A cycling theme toggle in the sidebar, defaulting to Browser, applying immediately and
persisting, with Browser following the OS; a single icon when the sidebar is collapsed.

**Independent Test**: Render the toggle; it shows the active mode's icon + label (Browser by default);
clicking it cycles light → dark → browser → light, calling `updateAppearance` with the next value.

### Tests for User Story 2 ⚠️

- [x] T005 [P] [US2] Create resources/js/components/theme-selector.test.tsx: with `appearance: 'system'` the toggle shows the Browser icon + label; clicking cycles to the next mode — assert `updateAppearance` is called with `'light'` from `system`, `'dark'` from `light`, `'system'` from `dark` (re-render per mocked appearance); assert the label text renders for each mode (Light/Dark/Browser). Mock `@/hooks/use-appearance`

### Implementation for User Story 2

- [x] T006 [P] [US2] Create resources/js/components/theme-selector.tsx: consume `useAppearance`; a single toggle that **cycles** light → dark → browser → light on click (`updateAppearance(next)`); show the active mode's icon (`Sun`/`Moon`/`Monitor`) + label (**Light**/**Dark**/**Browser**); hide the label when the sidebar is collapsed (`group-data-[collapsible=icon]:hidden`, icon-only); **role-named color classes + shared primitives only** (no raw `neutral-*`/hex — constitution VI). FR-009/FR-010
- [x] T007 [US2] Render `<ThemeSelector />` inside the kept sidebar skeleton (footer, above `NavUser`) in resources/js/components/app-sidebar.tsx (depends on T004, T006)
- [x] T008 [US2] Extend resources/js/components/app-sidebar.test.tsx to assert the theme toggle is present in the sidebar (depends on T002, T007)

**Checkpoint**: Full feature — rebranded sidebar carrying the theme selector.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [x] T009 [P] Run `npm run test -- theme-selector app-sidebar`; confirm green
- [ ] T010 Run quickstart.md manual verification: Optistock title + user menu kept + no nav/links/team-switcher; cycling toggle switches immediately, persists across reload, Browser follows the OS; collapsed sidebar shows the toggle as a single icon

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (P1)** → no deps (confirm-only).
- **Foundational (P2)** → none.
- **US1 (P3)** → the MVP; `app-logo` (T003) and `app-sidebar` strip (T004) are independent files except they share the test file with US2.
- **US2 (P4)** → after US1's T004 for the shared `app-sidebar.tsx` wiring (T007) and shared test (T008).
- **Polish (P5)** → after the stories.

### Key cross-task dependencies

- T007 ← T004, T006; T008 ← T002, T007.
- **Shared file `app-sidebar.tsx`**: T004 → T007 sequential. **Shared test `app-sidebar.test.tsx`**: T002 → T008 sequential.

### Parallel Opportunities

- US1: T002 (test) ∥ T003 (app-logo). T004 after.
- US2: T005 (test) ∥ T006 (component). Then T007 → T008.

---

## Parallel Example: User Story 1

```bash
Task: "Create app-sidebar.test.tsx asserting Optistock + no starter-kit entries"
Task: "Rename the brand title to Optistock in app-logo.tsx"
```

---

## Implementation Strategy

### MVP First (US1)

1. Setup (T001) → US1 (T002–T004).
2. **STOP & VALIDATE**: sidebar shows Optistock, no starter-kit content.

### Incremental Delivery

1. US1 → clean rebranded sidebar (MVP).
2. US2 → theme selector wired in.
3. Polish → test sweep + quickstart.

---

## Notes

- Frontend-only; no backend, no new theming engine — `useAppearance` reused as-is.
- Constitution VI: the new selector MUST use role-named colors (avoid the raw `neutral-*` in `appearance-tabs.tsx`).
- D2: the account/auth user menu (`NavUser`, incl. logout) is KEPT (user decision); only the nav/links/team-switcher are removed.
- The theme toggle cycles (light→dark→browser) and collapses to a single icon (FR-010 / SC-005).
- Commit after each task or logical group.
