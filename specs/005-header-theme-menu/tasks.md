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

- [ ] T001 Confirm `resources/js/hooks/use-appearance.tsx` is reused unchanged — it is the standard starter-kit theming method (light/dark/system, default system) and already covers FR-005/006/007/008. No edit; this is the basis the selector builds on.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: None. The theming hook already exists; both stories build directly on it. No blocking work.

---

## Phase 3: User Story 1 - See Optistock branding instead of the starter kit (Priority: P1) 🎯 MVP

**Goal**: The sidebar shows the Optistock title (logo placeholder kept) and none of the starter-kit
content beneath it, with the skeleton + responsiveness preserved.

**Independent Test**: Render the sidebar; confirm "Optistock" title, logo placeholder present, and no
Dashboard / Repository / Documentation / team switcher / user menu entries.

### Tests for User Story 1 ⚠️ (write first, ensure they fail)

- [ ] T002 [P] [US1] Create resources/js/components/app-sidebar.test.tsx: renders "Optistock"; does NOT render the starter-kit entries (Dashboard, Repository, Documentation, team-switcher trigger, user menu). Mock `@inertiajs/react` `usePage` (auth user + currentTeam/teams) and `@/hooks/use-appearance`

### Implementation for User Story 1

- [ ] T003 [P] [US1] Change the title text "Laravel Starter Kit" → "Optistock" (keep `AppLogoIcon` placeholder) in resources/js/components/app-logo.tsx
- [ ] T004 [US1] Remove `TeamSwitcher`, `NavMain`, `NavFooter`, `NavUser` (and their now-unused imports + the `mainNavItems`/`footerNavItems` arrays) while keeping the `Sidebar` / `SidebarHeader` / `SidebarContent` / `SidebarFooter` skeleton and the brand row, in resources/js/components/app-sidebar.tsx. **Note (D2)**: this drops the only logout/profile control in this layout — intended per spec (out of scope to relocate); Fortify backend untouched

**Checkpoint**: Sidebar is rebranded + stripped. MVP demoable.

---

## Phase 4: User Story 2 - Choose the colour theme (Priority: P1)

**Goal**: A Light / Dark / Browser selector in the sidebar, defaulting to Browser, applying immediately
and persisting, with Browser following the OS.

**Independent Test**: Render the selector; the active option reflects the current appearance (Browser by
default); clicking Light/Dark/Browser calls `updateAppearance('light'|'dark'|'system')`.

### Tests for User Story 2 ⚠️

- [ ] T005 [P] [US2] Create resources/js/components/theme-selector.test.tsx: renders the three options (Light, Dark, Browser); the active option reflects the mocked `appearance`; clicking each calls `updateAppearance` with `light`/`dark`/`system`; with `appearance: 'system'` the Browser option is marked active. Mock `@/hooks/use-appearance`

### Implementation for User Story 2

- [ ] T006 [P] [US2] Create resources/js/components/theme-selector.tsx: consume `useAppearance`; render three mutually-exclusive options labelled **Light** / **Dark** / **Browser** (values `light`/`dark`/`system`); mark the active one (FR-009); call `updateAppearance` on select; **role-named color classes + shared primitives only** (no raw `neutral-*`/hex — constitution VI)
- [ ] T007 [US2] Render `<ThemeSelector />` inside the kept sidebar skeleton (footer or content) in resources/js/components/app-sidebar.tsx (depends on T004, T006)
- [ ] T008 [US2] Extend resources/js/components/app-sidebar.test.tsx to assert the theme selector is present in the sidebar (depends on T002, T007)

**Checkpoint**: Full feature — rebranded sidebar carrying the theme selector.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [ ] T009 [P] Run `npm run test -- theme-selector app-sidebar`; confirm green
- [ ] T010 Run quickstart.md manual verification: Optistock title + no starter-kit content; Light/Dark/Browser switch immediately, persist across reload, Browser follows the OS; sidebar still collapses/responsive

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
- D2 logout removal is intentional per the approved spec; revisit in a later feature if an Optistock user menu is wanted.
- Commit after each task or logical group.
