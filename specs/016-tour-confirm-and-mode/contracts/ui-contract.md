# UI Contract — Result view: new-tour confirmation + mode selector

No network contract changes. The only API touched is the **existing**
`GET /api/tour/drivers?mode&date&tour` (feature 006/014), now called with the
**presentation-selected mode** instead of a fixed optimization mode. Request/response shape
is unchanged; see `specs/014-driver-workday-preview/contracts/driver-workday.md`.

## Result view (`ResultSummary`) — behavior contract

### Header bar
- Contains, left→right: tour figures (time on road, tour duration, selected date) · a
  **delivery-mode selector** (`ModeSelect`) · **New tour** button · **Assign Driver** button.
- The mode selector shows the effective driver mode; on first display of a tour it equals the
  mode the tour was optimized with.

### New-tour confirmation
- Clicking **New tour** opens a modal confirmation (does **not** reset yet):
  - Title: `Make a new tour?`
  - Body: `Are you sure you want to make a new tour? The tour will remain unassigned.`
  - Buttons: `Cancel` (outline) · `Confirm`
- `Confirm` → discards the current tour and returns to the editing view (same as today's reset).
- `Cancel` / dismiss (overlay, Esc) → closes with no change to the displayed tour, mode,
  driver list, or selected driver.

### Mode switch
- Changing the selector → the driver list reloads for the newly selected mode
  (`GET /api/tour/drivers?mode=<new>&date&tour`); while pending, the loading state shows (no
  stale list).
- Any currently selected driver is deselected; the map workday preview is removed until a driver
  is (re)selected under the new mode.
- The candidate tour's stop order and drawn polyline are **unchanged** by the switch.
- Selecting a mode with no qualifying drivers → the existing "No one available for this
  delivery." state.

## Assignment confirmation (`AssignDriverDialog`) — unchanged
Same trigger (**Assign Driver**, enabled only with a selected driver), same
`POST` assignment with `start_index`, same confirm/cancel behavior. Internally re-rendered via
the shared `ConfirmDialog`, with identical copy and outcomes.
