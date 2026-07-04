# Implementation Plan: New-Tour Confirmation & Presentation-Layer Mode Selector

**Branch**: `016-tour-confirm-and-mode` | **Date**: 2026-07-04 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/016-tour-confirm-and-mode/spec.md`, building on
features 003 (`ModeSelect` mode dropdown), 006/011 (mode+date driver list `useTourDrivers`),
012 (`AssignDriverDialog` confirm pop-up), 014 (`selectedDriver` lifted to the page,
`useWorkdayPreview`, "Assign Driver" button).

## Summary

Two small **frontend-only** edits to the result view. No backend, no endpoints, no migrations —
the drivers endpoint already accepts `mode`.

1. **New-tour confirmation.** "New tour" no longer resets immediately; it opens a confirmation
   pop-up mirroring the assignment one, with the copy **"Are you sure you want to make a new
   tour? The tour will remain unassigned."** Confirm → today's reset; Cancel → no-op. To avoid
   duplicating the assignment pop-up's markup, factor a shared **`ConfirmDialog`** and render
   both pop-ups through it (assignment behavior unchanged).
2. **Presentation-view mode selector.** Reuse `ModeSelect` in `ResultSummary`'s header. Add
   page state `presentationMode` (`null` = follow the tour's optimization mode); the **effective
   driver mode** `= presentationMode ?? state.mode` feeds `DriverList` and `useWorkdayPreview`.
   Changing it reloads the driver list (existing `useTourDrivers` mode-keying) and clears the
   selected driver. The candidate tour's stop order + polyline stay on `state.mode` — no
   re-optimize, no re-trace (FR-009).

## Technical Context

**Stack**: Laravel 12 (PHP) + React 19 + Inertia + Tailwind v4 + shadcn/ui; MapLibre GL via
react-map-gl; Vitest + Testing Library (frontend), PHPUnit (unchanged this feature).

**Existing pieces reused (unchanged behavior)**:
- `Dialog` primitive (`components/ui/dialog.tsx`) — base of the shared `ConfirmDialog`.
- `AssignDriverDialog` (012) — refactored to render `ConfirmDialog`; assignment logic + copy +
  outcomes untouched.
- `ModeSelect` (003) — already styled for a `bg-primary text-text-on-color` bar (= the result
  header), dropped in as-is.
- `useTourDrivers(mode, date, tourId)` (006/011) — refetches on mode change, never shows a stale
  list → FR-008/FR-011 for free.
- `useWorkdayPreview(driver, mode, key)` (014) — a re-selected driver's preview traced for the
  effective driver mode.
- Page-level `selectedDriver` + its clear-on-reset/date-change (014) — extended to clear on
  mode change.

**Project Type**: web app (Laravel + React SPA). This feature: React only.

**Performance/Constraints**: no added network calls beyond the driver-list refetch that a mode
change already implies; no re-optimization/re-trace on switch (FR-009). Trivial scope.

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — new/changed surfaces covered: `confirm-dialog` (renders title/
  body/buttons; Confirm calls `onConfirm`; Cancel/dismiss calls `onOpenChange(false)`; `pending`
  disables); `assign-driver-dialog` (existing tests still green through the shared component —
  same title/desc, confirm assigns, failure stays open); `result-summary` (New tour opens the
  confirm and does **not** reset until Confirm; Cancel = no reset; `ModeSelect` renders and its
  change calls `onDriverModeChange`; `DriverList` receives the effective mode); `optimize` page
  (effective driver mode defaults to `state.mode`; picking a mode reloads list + clears
  selection; reset returns to follow-optimization-mode). PASS.
- **II/III. Readable & Simple** — removes duplication rather than adding it: one `ConfirmDialog`
  serves both pop-ups (single job: confirm/cancel modal). Presentation mode is one nullable page
  state with a one-line `?? state.mode` derivation; no new hook, no context, no abstraction
  layer. PASS.
- **IV. Robustness** — Cancel paths are pure no-ops (no reset, no reload, no lost selection);
  mode-keyed `useTourDrivers` already guards against stale lists and surfaces load/error/empty
  states; no new failure path introduced (no new I/O). PASS.
- **V. Performance with Clarity** — no extra calls beyond the intended driver-list refetch;
  candidate geometry untouched on switch. PASS.
- **VI. Consistent, Reusable Styling** — `ConfirmDialog` reuses the shared `Dialog` + existing
  button variants; `ModeSelect` reuses its existing role-named classes (`bg-primary`,
  `text-text-on-color`); no raw hex, no bespoke modal, no duplicated visual rule. PASS.

No violations. (Complexity Tracking omitted.)

## Decisions

Full rationale + alternatives in [research.md](research.md); condensed:

- **D1 — Shared `ConfirmDialog`.** Factor the header+title+description+Cancel/Confirm modal;
  `AssignDriverDialog` and the new-tour confirm both render it. Satisfies "reuse/copy the
  existing pop-up" via one component, not a paste. (research D1)
- **D2 — New-tour confirm in `ResultSummary`, gating `onReset`.** "New tour" opens the dialog;
  Confirm → `onReset`; Cancel → close only. Copy: title "Make a new tour?", body the
  user-supplied sentence, confirm "Confirm". Synchronous, no `pending`. (research D2)
- **D3 — `presentationMode` page state, effective mode `?? state.mode`.** Lives in
  `optimize.tsx` (also feeds the map preview); `null` follows the optimization mode (FR-007);
  set on pick + clears `selectedDriver` (FR-010); reset to `null` on `resetTour`. (research D3)
- **D4 — Candidate geometry stays on `state.mode`.** `useTourGeometry` unchanged → no
  re-optimize/re-trace on switch (FR-009). (research D4)
- **D5 — Preview cache key gains the effective mode** so a post-switch selection can't reuse a
  stale-mode trace. (research D5)

## Project Structure (feature-specific)

Frontend — **new**:
- `resources/js/components/ui/confirm-dialog.tsx` — presentational confirm modal
  (`open`, `onOpenChange`, `title`, `description`, `confirmLabel?`, `pending?`, `onConfirm`).

Frontend — **change**:
- `resources/js/components/tour/assign-driver-dialog.tsx` — render `ConfirmDialog` (keep
  `useAssignDriver`, `pending`, driver-derived open state + copy); no behavior change.
- `resources/js/components/tour/result-summary.tsx` — add `ModeSelect` to the header;
  `confirmingNewTour` state + `ConfirmDialog` for "New tour" (Confirm → `onReset`); new props
  `driverMode` / `onDriverModeChange`; pass `driverMode` to `DriverList`.
- `resources/js/pages/tour/optimize.tsx` — `presentationMode` state; effective driver mode
  `presentationMode ?? state.mode`; pass to `ResultSummary` (`driverMode`/`onDriverModeChange`)
  and `useWorkdayPreview`; on mode change clear `selectedDriver`; `resetTour` clears
  `presentationMode`; add effective mode to the preview cache key.

Tests: `resources/js/components/ui/confirm-dialog.test.tsx` (new),
`resources/js/components/tour/assign-driver-dialog.test.tsx` (still green — same outcomes),
`resources/js/components/tour/result-summary.test.tsx` (extend — new-tour confirm gating, mode
selector wiring), `resources/js/pages/tour/optimize.test.tsx` (extend — effective mode default,
mode change reloads list + clears selection, reset behavior).

Out of scope: re-optimizing / re-tracing on mode switch (FR-009 rejected alternative);
persisting the presentation mode across tours/sessions; any editing-view change (the 003
selector stays where it is).

## Flow (result view)

1. Tour optimized (mode *M*) → result view; `presentationMode = null`; effective mode = *M*;
   mode selector reads *M*; driver list = drivers for *M*.
2. Switch selector to *N* → `presentationMode = N`, `selectedDriver = null`; `useTourDrivers`
   refetches for *N* (loading → ready/empty/error); candidate route unchanged.
3. Pick a driver → `useWorkdayPreview` (mode *N*, key includes *N*) draws their day; **Assign
   Driver** enables (012/014 unchanged).
4. **New tour** → `ConfirmDialog` ("Make a new tour?" / user copy). Cancel → nothing changes.
   Confirm → `onReset` → `presentationMode = null`, selection cleared, editing view returns.

## API contracts (this run)

None changed. `GET /api/tour/drivers?mode&date&tour` is called with the presentation-selected
mode; shape unchanged (see `contracts/ui-contract.md` and feature 014's driver contract).

## Design Artifacts (this run)

- `research.md` — reused slice + decisions D1–D5.
- `data-model.md` — no DB/API change; frontend state + component props + invariants.
- `contracts/ui-contract.md` — result-view behavior contract; no network change.
- `quickstart.md` — manual + automated verification of both edits.

---

Generated by speckit.plan on 2026-07-04
