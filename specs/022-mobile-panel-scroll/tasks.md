---

description: "Task list for Mobile Scrollable Content Panel (022)"
---

# Tasks: Mobile Scrollable Content Panel

**Input**: Design documents from `/specs/022-mobile-panel-scroll/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/mobile-panel.md

**Tests**: Included as class-presence guards only. jsdom has no layout engine and does not evaluate media queries, so unit tests assert the `max-md:` override classes are applied; the real scroll / clipping / edge-to-edge behavior is verified manually via the quickstart.

**Organization**: Grouped by user story. US1 (scroll the panel to reach the list) is the MVP and unblocks the mobile flow; US2 (edge-to-edge bar) is cosmetic polish.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 or US2

## Path Conventions

Frontend-only, single repo: page in `resources/js/pages/tour/`, components in `resources/js/components/tour/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm a green baseline before the layout change.

- [X] T001 Run the current gate to confirm a clean baseline: `npm run test`, `npm run lint`, `npm run types`, `npm run format:check`.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: None. The `max-md:` responsive variant is built into Tailwind v4 — no shared setup is required before the user stories.

---

## Phase 3: User Story 1 - Reach the list on a phone by scrolling the panel (Priority: P1) 🎯 MVP

**Goal**: On phones, the bottom panel scrolls as one surface so the tall orange bar moves up (clipped beneath the map) and the full driver / stop list becomes reachable.

**Independent Test**: At ~360px in the result view, scroll the panel up and confirm the bar disappears under the map and every driver — including the last — is reachable and selectable; repeat in the editing view for the stop list.

### Tests for User Story 1 ⚠️ (write first, expected to fail until impl)

- [X] T002 [P] [US1] In `resources/js/pages/tour/optimize.test.tsx`, assert the bottom content panel element carries `max-md:overflow-y-auto`. (jsdom can't evaluate the media query — class presence is the guard.)
- [X] T003 [P] [US1] In `resources/js/components/tour/result-summary.test.tsx`, assert the `ResultSummary` root carries `max-md:h-auto`.
- [X] T004 [P] [US1] In `resources/js/components/tour/driver-list.test.tsx`, assert the driver `<ul>` carries `max-md:flex-none`.
- [X] T005 [P] [US1] In `resources/js/components/tour/stop-list.test.tsx`, assert the `StopList` root carries `max-md:h-auto` and its `<ul>` carries `max-md:flex-none`.

### Implementation for User Story 1

- [X] T006 [US1] In `resources/js/pages/tour/optimize.tsx`, add `max-md:overflow-y-auto` to the bottom content panel `<div>` (the one currently `flex min-h-0 flex-1 flex-col gap-3 overflow-hidden border-t border-border p-4`) so it becomes a single scroll surface on mobile. (Same file as T012 — sequence before it.)
- [X] T007 [P] [US1] In `resources/js/components/tour/result-summary.tsx`, add `max-md:h-auto` to the root `flex h-full flex-col gap-3` so the content is natural height on mobile.
- [X] T008 [P] [US1] In `resources/js/components/tour/driver-list.tsx`, add `max-md:flex-none` to the `<ul>` so the list is natural height and part of the single panel scroll on mobile.
- [X] T009 [P] [US1] In `resources/js/components/tour/stop-list.tsx`, add `max-md:h-auto` to the root `flex h-full flex-col gap-3` and `max-md:flex-none` to the `<ul>` (editing view — same behavior as the result view).

**Checkpoint**: On a phone the panel scrolls, the bar clips beneath the map, and the whole list is reachable — MVP complete.

---

## Phase 4: User Story 2 - Edge-to-edge orange bar on mobile (Priority: P2)

**Goal**: On phones the orange bar sits flush to the panel's side edges with no framing background border; desktop keeps its inset + rounded corners.

**Independent Test**: At ~360px, confirm the bar touches both side edges with no dark border and square corners; at desktop width, confirm the inset padding + rounding are unchanged.

### Tests for User Story 2 ⚠️ (write first)

- [X] T010 [P] [US2] In `resources/js/components/tour/tour-control-bar.test.tsx`, assert the bar root carries `max-md:rounded-none`.
- [X] T011 [P] [US2] In `resources/js/components/tour/result-summary.test.tsx`, assert the `bg-primary` header bar carries `max-md:rounded-none`.

### Implementation for User Story 2

- [X] T012 [US2] In `resources/js/pages/tour/optimize.tsx`, add `max-md:p-0` to the bottom content panel `<div>` so the framing padding is removed on mobile (bar reaches the screen edges). This intentionally makes the whole panel full-bleed on mobile — the list rows also reach the edges (confirmed desired). (Same file as T006 — sequence after it.)
- [X] T013 [P] [US2] In `resources/js/components/tour/tour-control-bar.tsx`, add `max-md:rounded-none` to the bar root so it is flush on mobile.
- [X] T014 [US2] In `resources/js/components/tour/result-summary.tsx`, add `max-md:rounded-none` to the `bg-primary` header bar. (Same file as T007 — sequence after it.)

**Checkpoint**: The bar is edge-to-edge on mobile, unchanged on desktop.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [~] T015 NOT run in this environment (no browser to drive at ~360px). The `max-md:` classes are guarded by unit tests + a successful build, but the visual walkthrough per `specs/022-mobile-panel-scroll/quickstart.md` — panel scrolls vertically with NO horizontal page scroll, bar disappears beneath the map and never draws over it, bar + list full-bleed, last list item reachable + selectable in both views, AND desktop unchanged — is still REQUIRED before release.
- [X] T016 Run the FULL CI gate before done: `npm run test`, `npm run lint`, `npm run types`, `npm run format:check`, and `npm run build` (confirms the `max-md:` classes compile) — all green.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: empty — Tailwind provides `max-md:`.
- **US1 (Phase 3)**: after Setup.
- **US2 (Phase 4)**: after Setup; independent of US1 except the shared `optimize.tsx` panel (T012 after T006) and `result-summary.tsx` (T014 after T007).
- **Polish (Phase 5)**: after US1 (+US2 for the edge-to-edge check).

### User Story Dependencies

- **US1 (P1)**: independent — unblocks reaching the list on mobile (the reported bug).
- **US2 (P2)**: independent behavior; only file-level ordering ties it to US1 (`optimize.tsx`, `result-summary.tsx`).

### Within User Story 1

- Tests (T002–T005) first, expected to fail.
- Impl: T006 (panel) then T007/T008/T009 (different component files, parallelizable).

### Parallel Opportunities

- US1 tests T002–T005 (different files) run in parallel; US2 tests T010–T011 in parallel.
- Impl T007, T008, T009 (different component files) run in parallel; T013 parallel with them.
- T006 and T012 share `optimize.tsx` → sequential. T007 and T014 share `result-summary.tsx` → sequential.

---

## Parallel Example: User Story 1 tests

```bash
Task: "optimize.test.tsx asserts panel max-md:overflow-y-auto"
Task: "result-summary.test.tsx asserts root max-md:h-auto"
Task: "driver-list.test.tsx asserts ul max-md:flex-none"
Task: "stop-list.test.tsx asserts root max-md:h-auto + ul max-md:flex-none"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Setup → 2. (Foundational empty) → 3. US1 (panel scrolls, list reachable) → **STOP & VALIDATE** at ~360px → demo.

### Incremental Delivery

1. Setup → ready.
2. US1 → list reachable on mobile → demo (MVP — the actual fix).
3. US2 → edge-to-edge bar → demo (polish).

---

## Notes

- Every change is an additive `max-md:` variant — desktop classes are left literally unchanged, so the desktop layout is provably unaffected (the user's hard constraint).
- No JS, no data, no new colors. Minimal + clean.
- Real scroll / clipping / edge-to-edge is visual; unit tests only guard that the `max-md:` classes are applied. The quickstart is the behavioral verification (jsdom evaluates no media queries).
