---

description: "Task list for Mobile Responsive Interface (021)"
---

# Tasks: Mobile Responsive Interface

**Input**: Design documents from `/specs/021-mobile-responsive/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/responsive-bars.md

**Tests**: Included as class-presence guards only. CSS overflow/scroll is not observable in jsdom (no layout engine), so unit tests assert the shared scroll style is applied; the actual scroll behavior is verified manually via the quickstart.

**Organization**: Grouped by user story. US1 (scrollable overflowing bars) is the MVP and the core mechanism; US2 (no page-level horizontal overflow) is a lighter, mostly-verification increment.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 or US2

## Path Conventions

Frontend-only, single repo: styles in `resources/css/`, components in `resources/js/components/tour/`, page in `resources/js/pages/tour/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm a green baseline before the style change.

- [ ] T001 Run the current gate to confirm a clean baseline: `npm run test`, `npm run lint`, `npm run types`, `npm run format:check`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The single shared style both bars depend on.

**⚠️ CRITICAL**: Both user stories apply this utility — it must exist first.

- [ ] T002 Add a reusable Tailwind v4 `@utility scroll-x-contained { overflow-x: auto; overscroll-behavior-x: contain; }` to `resources/css/app.css` (single source per Constitution VI; intent-named).

**Checkpoint**: The shared scroll style exists and compiles.

---

## Phase 3: User Story 1 - Reach every control on a narrow screen via a scrollable bar (Priority: P1) 🎯 MVP

**Goal**: When a main bar overflows the viewport it scrolls horizontally within its own rounded box instead of spilling out and being clipped; when it fits, it looks exactly as today.

**Independent Test**: At ~360px and ~320px, confirm both the editing bar and the result bar scroll sideways to expose and operate every control, no control renders outside the box, and the desktop layout is unchanged.

### Tests for User Story 1 ⚠️ (write first, expected to fail until impl)

- [ ] T003 [P] [US1] New Vitest `resources/js/components/tour/tour-control-bar.test.tsx`: render `TourControlBar` and assert its root element carries the `scroll-x-contained` class. (jsdom cannot measure real overflow — class presence is the guard.)
- [ ] T004 [P] [US1] In `resources/js/components/tour/result-summary.test.tsx`, add a test asserting the `bg-primary` header bar carries the `scroll-x-contained` class.

### Implementation for User Story 1

- [ ] T005 [P] [US1] In `resources/js/components/tour/tour-control-bar.tsx`, add `scroll-x-contained` to the bar root and `shrink-0` to its two child groups (the `Options` control group and the Optimize `ActionButton`) so controls keep intrinsic width and the bar scrolls rather than squishing. Do not change the fits-wide layout.
- [ ] T006 [P] [US1] In `resources/js/components/tour/result-summary.tsx`, add `scroll-x-contained` to the `bg-primary` header bar and `shrink-0` to its two child groups (the figures grid and the New/Edit/Assign button group). Do not change the fits-wide layout.

**Checkpoint**: Both bars scroll on overflow, contained in their box, with the desktop look intact — MVP complete.

---

## Phase 4: User Story 2 - Use the tour screen without a broken layout on mobile (Priority: P2)

**Goal**: At phone widths the tour screen has no whole-page horizontal scroll and the map + content areas stay usable.

**Independent Test**: At phone width, confirm no page-level horizontal scrollbar and that the map and the stop-list / result area are both visible and interactive.

### Implementation for User Story 2

- [ ] T007 [US2] Verify in `resources/js/pages/tour/optimize.tsx` that no element forces page-level horizontal overflow at mobile widths (the content column is already `overflow-hidden`, and US1 stops the bar spill). Add `min-w-0` / an overflow guard ONLY if the narrow-viewport check surfaces a leak; otherwise change nothing and record that the existing clipping suffices.

**Checkpoint**: The tour screen holds together at phone widths.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [ ] T008 Run the quickstart walkthrough in `specs/021-mobile-responsive/quickstart.md` at ~360px and ~320px: both bars scroll and expose every control, nothing spills outside the rounded box, the bar menus (mode dropdown, native date picker) still open, no whole-page horizontal scroll, scroll-end trailing padding holds, and no vertical clipping. **No-deformation proof**: capture a before/after desktop-width screenshot of each bar and confirm they are pixel-identical (the fits state cannot be guarded by a jsdom unit test).
- [ ] T009 Run the FULL CI gate before done: `npm run test`, `npm run lint`, `npm run types`, and `npm run format:check` (format is separate from lint) — all green.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: after Setup — blocks both stories (both apply `scroll-x-contained`).
- **US1 (Phase 3)**: after Foundational.
- **US2 (Phase 4)**: after Foundational; in practice easiest to verify after US1 removes the bar spill.
- **Polish (Phase 5)**: after US1 (+US2 for the page check).

### User Story Dependencies

- **US1 (P1)**: independent — delivers the whole scroll-on-overflow mechanism.
- **US2 (P2)**: independent behavior (page frame); benefits from US1 being in place for the manual check.

### Within User Story 1

- Tests (T003, T004) first, expected to fail.
- T002 (utility) before T005/T006 (apply it).
- T005 and T006 touch different component files → parallelizable once T002 lands.

### Parallel Opportunities

- T003 and T004 (different test files) run in parallel.
- T005 and T006 (different component files) run in parallel after T002.

---

## Parallel Example: User Story 1

```bash
# Tests together:
Task: "tour-control-bar.test.tsx asserts scroll-x-contained on the bar root"
Task: "result-summary.test.tsx asserts scroll-x-contained on the header bar"

# Then both bar edits together (after T002):
Task: "Apply scroll-x-contained + shrink-0 in tour-control-bar.tsx"
Task: "Apply scroll-x-contained + shrink-0 in result-summary.tsx"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Setup → 2. Foundational (`scroll-x-contained`) → 3. US1 (apply to both bars) → **STOP & VALIDATE** at narrow widths → demo.

### Incremental Delivery

1. Setup + Foundational → shared style ready.
2. US1 → both bars scroll on overflow → demo (MVP).
3. US2 → confirm/patch page-level overflow → demo.

---

## Notes

- Keep the change additive and single-sourced: one utility in `app.css`, applied identically to both bars (Constitution VI).
- The fits-wide (desktop) state must be pixel-identical — `overflow-x: auto` + `shrink-0` must not alter it.
- Real scroll/overflow is a visual property; unit tests only guard that the style is applied. The quickstart is the behavioral verification.
- No backend, no data, no new colors.
