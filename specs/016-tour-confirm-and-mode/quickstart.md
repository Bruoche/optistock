# Quickstart — New-Tour Confirmation & Presentation-Layer Mode Selector

Manual verification of the two result-view edits. Assumes at least two drivers whose
`modes` differ (e.g. one trucking-only, one walking-capable) and a tour that can be optimized.

## Setup
1. `composer install && npm install`, run the app (`npm run dev` + Laravel serve), log in.
2. Add ≥2 stops, pick a mode (e.g. trucking), **Optimize** → the result view appears.

## New-tour confirmation
1. Click **New tour** → a modal appears:
   - Title **"Make a new tour?"**, body **"Are you sure you want to make a new tour? The tour
     will remain unassigned."**, buttons **Cancel** / **Confirm**.
   - The tour is still on screen (not discarded).
2. Click **Cancel** → dialog closes, the tour, its driver list, and any selected driver are
   exactly as before.
3. Click **New tour** again → **Confirm** → the tour clears and the editing view (control bar +
   stop list) returns. ✅ FR-001..FR-005, SC-001/SC-002.
4. Confirm the **Assign Driver** dialog still works as before (its own confirmation, unchanged).

## Presentation-view mode switch
1. Optimize a tour (trucking) → note the driver list (trucking-capable drivers) and the mode
   selector reading **Trucking**.
2. Change the selector to **Walking** → the driver list reloads to walking-capable drivers
   (loading indicator briefly), each projected time recomputed for walking. ✅ FR-006..FR-008,
   FR-011, SC-003.
3. Select a driver (workday preview draws on the map), then switch the mode again → the
   selection clears and the preview disappears until you pick a driver under the new mode.
   ✅ FR-010.
4. Switch to a mode with no qualifying drivers → **"No one available for this delivery."**
   ✅ FR-012.
5. Throughout, confirm the drawn candidate route/polyline and stop order **do not change** when
   switching mode — only the driver list does. ✅ FR-009.
6. Click **New tour** → Confirm, optimize a fresh tour with a different mode → the selector
   starts at that new tour's optimization mode. ✅ FR-007, SC-005.

## Automated checks
- `npm run test` (Vitest): `confirm-dialog`, `assign-driver-dialog` (unchanged behavior via the
  shared component), `result-summary` (New-tour opens confirm → reset only on confirm; mode
  selector renders + drives `DriverList` mode; selection cleared on mode change),
  `optimize` page (effective driver mode default + reset behavior).
- `npm run lint && npm run types`.
