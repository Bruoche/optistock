---
description: "Task list for New-Tour Confirmation & Presentation-Layer Mode Selector"
---

# Tasks: New-Tour Confirmation & Presentation-Layer Mode Selector

**Input**: Design documents from `specs/016-tour-confirm-and-mode/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/ui-contract.md

**Tests**: Included — the constitution requires tests for behavior affecting correctness, and
plan.md enumerates them.

**Scope**: Frontend only (React). No backend, endpoints, or migrations — the drivers endpoint
already accepts `mode`.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1 = new-tour confirmation, US2 = presentation-view mode selector

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm a clean baseline before changing shared components.

- [X] T001 Verify baseline green on branch `016-tour-confirm-and-mode`: run `npm run test`, `npm run lint`, `npm run types` and confirm they pass before edits.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Extract the shared confirm modal both pop-ups will use. Blocks US1.

**⚠️ CRITICAL**: `ConfirmDialog` and the `AssignDriverDialog` refactor must land (and the
existing assignment tests stay green) before US1's new-tour confirm is built on it.

- [X] T002 [P] Create shared `ConfirmDialog` in `resources/js/components/ui/confirm-dialog.tsx` — presentational wrapper over `Dialog`/`DialogHeader`/`DialogTitle`/`DialogDescription`/`DialogFooter`; props `{ open, onOpenChange, title, description, confirmLabel?, pending?, onConfirm }`; Cancel = outline button (label "Cancel") calling `onOpenChange(false)`; Confirm button (label `confirmLabel ?? "Confirm"`) calling `onConfirm`; both disabled while `pending`.
- [X] T003 [P] Add `resources/js/components/ui/confirm-dialog.test.tsx` — renders title/description/buttons; Confirm calls `onConfirm`; Cancel and dismiss call `onOpenChange(false)`; `pending` disables both buttons.
- [X] T004 Refactor `resources/js/components/tour/assign-driver-dialog.tsx` to render `ConfirmDialog` (title "Assign this delivery?", existing description, `confirmLabel="Confirm"`, `pending` from `useAssignDriver`, `onConfirm=handleConfirm`, open when `driver !== null`); keep all assignment logic and outcomes unchanged (depends on T002).
- [X] T005 Confirm `resources/js/components/tour/assign-driver-dialog.test.tsx` still passes unchanged (same title/description, confirm assigns, failure stays open); adjust only test-internal selectors if the shared component changes DOM structure — no behavior change (depends on T004).

**Checkpoint**: Shared `ConfirmDialog` exists; assignment pop-up works exactly as before.

---

## Phase 3: User Story 1 - Confirm before dropping the current tour (Priority: P1) 🎯 MVP

**Goal**: "New tour" opens a confirmation; Confirm resets, Cancel is a no-op.

**Independent Test**: From a displayed tour, "New tour" → confirm clears to editing view;
"New tour" → cancel leaves the tour unchanged.

### Tests for User Story 1

- [X] T006 [US1] Extend `resources/js/components/tour/result-summary.test.tsx`: clicking "New tour" opens the confirm dialog and does NOT call `onReset`; confirming calls `onReset`; cancelling/dismissing calls neither `onReset` nor `onAssigned` and leaves the driver list untouched; assert the dialog body text "Are you sure you want to make a new tour? The tour will remain unassigned.".

### Implementation for User Story 1

- [X] T007 [US1] In `resources/js/components/tour/result-summary.tsx`, add `confirmingNewTour` state; change the "New tour" `ActionButton` `onClick` to open it (no direct `onReset`); render `ConfirmDialog` with title "Make a new tour?", description "Are you sure you want to make a new tour? The tour will remain unassigned.", `onConfirm={() => { setConfirmingNewTour(false); onReset(); }}`, `onOpenChange` closing it. Leave `AssignDriverDialog` untouched.

**Checkpoint**: US1 fully functional and independently testable.

---

## Phase 4: User Story 2 - Switch delivery mode from the presentation view (Priority: P1)

**Goal**: A mode selector in the result header reloads the driver list for the chosen mode;
the candidate route is unchanged; selecting a mode clears the selected driver.

**Independent Test**: On a displayed tour, change the selector → driver list reloads for the
new mode; selecting a driver then switching clears the selection; route/polyline unchanged.

**Note**: T009 edits `result-summary.tsx` (same file as T007) — sequence US2 after US1.

### Tests for User Story 2

- [X] T008 [P] [US2] Extend `resources/js/pages/tour/optimize.test.tsx`: on a done result, the effective driver mode defaults to `state.mode`; changing the presentation mode reloads `DriverList`/`useTourDrivers` with the new mode and clears `selectedDriver`; `resetTour` clears `presentationMode` back to follow the next tour's optimization mode; the candidate `useTourGeometry` mode stays `state.mode` (unchanged by the switch).
- [X] T009 [P] [US2] Extend `resources/js/components/tour/result-summary.test.tsx`: rename the existing `mode` prop in the `renderSummary` helper to `driverMode` and add an `onDriverModeChange` default; keep the existing `useTourDrivers` mock 2-arg shape `(mode, date)` and its `toHaveBeenCalledWith('driving', DATE)` assertion (the value stays `'driving'`); add cases — `ModeSelect` renders in the header showing `driverMode`; changing it calls `onDriverModeChange`; `DriverList` receives `driverMode` as its `mode` prop.

### Implementation for User Story 2

- [X] T010 [US2] In `resources/js/components/tour/result-summary.tsx`, **rename** the `mode` prop to `driverMode: DeliveryMode` (it fed only `DriverList`) and add `onDriverModeChange: (m: DeliveryMode) => void`; render `<ModeSelect value={driverMode} onChange={onDriverModeChange} />` in the header bar; pass `mode={driverMode}` to `DriverList`. Update the caller `optimize.tsx` prop name accordingly (see T011) (depends on T007).
- [X] T011 [US2] In `resources/js/pages/tour/optimize.tsx`, add `presentationMode: DeliveryMode | null` state; compute `effectiveDriverMode = presentationMode ?? state.mode` (when done); pass `driverMode={effectiveDriverMode}` and an `onDriverModeChange` (sets `presentationMode` + `setSelectedDriver(null)`) to `ResultSummary`; feed `effectiveDriverMode` to `useWorkdayPreview` and add it to the preview cache key; set `presentationMode` to `null` in `resetTour` (depends on T010).

**Checkpoint**: US1 and US2 both work; both dialogs and the mode selector coexist.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [X] T012 Run `npm run lint`, `npm run types`, `npm run test` — all green.
- [X] T013 Run the `specs/016-tour-confirm-and-mode/quickstart.md` manual walkthrough (new-tour confirm cancel/confirm; mode switch reloads list, clears selection, leaves route unchanged; selector defaults to optimization mode on a fresh tour).

---

## Dependencies & Execution Order

- **Phase 1 (Setup)**: no dependencies.
- **Phase 2 (Foundational)**: T002 → T004 → T005; T003 parallel with T004/T005. Blocks US1.
- **US1 (Phase 3)**: after Foundational. T006 (test) before/with T007.
- **US2 (Phase 4)**: T010 shares `result-summary.tsx` with T007 → run US2 after US1. T011 depends on T010. Tests T008/T009 are [P] with each other.
- **Polish (Phase 5)**: after US1 + US2.

### Within stories
- Write the failing test first (T006 before T007; T008/T009 before T010/T011).

## Parallel Opportunities

- T002 + T003 (component + its test, different files).
- T008 + T009 (two different test files).
- US1 and US2 are **not** parallel: both edit `result-summary.tsx`.

## Implementation Strategy

- **MVP** = Phase 1 + Phase 2 + Phase 3 (US1): the new-tour confirmation, shipped on the shared
  `ConfirmDialog`. Validate, then add US2 (mode selector).
- Commit after each task or logical group; keep the assignment flow green throughout.
