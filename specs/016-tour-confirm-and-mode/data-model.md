# Data Model — New-Tour Confirmation & Presentation-Layer Mode Selector

No database changes, no API request/response changes. State + component props only.

## Frontend state (`resources/js/pages/tour/optimize.tsx`)

- **`presentationMode: DeliveryMode | null`** — the mode chosen in the result view.
  - `null` ⇒ follow the displayed tour's optimization mode.
  - **Effective driver mode** `= presentationMode ?? state.mode` (only meaningful while
    `state.status === 'done'`).
  - Transitions:
    - result shown / not yet touched → `null` (follows `state.mode`) — FR-007.
    - user picks mode *m* in result view → `m`, **and** `selectedDriver → null` — FR-010.
    - `resetTour()` → `null` — next tour starts at its own optimization mode.

- **`selectedDriver: Driver | null`** (existing, 014) — additionally cleared on presentation
  mode change, exactly as it already clears on date change / reset.

## Component contracts (UI)

### `ConfirmDialog` (new — `resources/js/components/ui/confirm-dialog.tsx` or `tour/`)
Presentational wrapper over the shared `Dialog` primitive.

| Prop | Type | Meaning |
|------|------|---------|
| `open` | `boolean` | dialog visibility |
| `onOpenChange` | `(open: boolean) => void` | close/dismiss |
| `title` | `ReactNode` | `DialogTitle` |
| `description` | `ReactNode` | `DialogDescription` (body copy) |
| `confirmLabel` | `string?` | confirm button text (default `"Confirm"`) |
| `pending` | `boolean?` | disables both buttons while an async confirm runs |
| `onConfirm` | `() => void` | confirm action |

Cancel button = outline, label `"Cancel"`, calls `onOpenChange(false)`.

### `AssignDriverDialog` (refactor — same public props)
Renders `ConfirmDialog` with `title="Assign this delivery?"`, its existing description, and its
`useAssignDriver` confirm + `pending`. **No behavior change** to the assignment flow.

### `ResultSummary` (change)
New props:

| Prop | Type | Meaning |
|------|------|---------|
| `driverMode` | `DeliveryMode` | effective mode for the driver list (was previously the fixed `mode`) |
| `onDriverModeChange` | `(mode: DeliveryMode) => void` | result-view mode selector change |

- Renders `<ModeSelect value={driverMode} onChange={onDriverModeChange} />` in the header bar.
- `<DriverList mode={driverMode} … />` (was `mode={state.mode}`).
- Holds `confirmingNewTour: boolean`; **"New tour"** opens it; `ConfirmDialog` confirm → `onReset`.
- Keeps `AssignDriverDialog` (unchanged trigger).

### `DriverList` (unchanged)
Still `mode`-keyed; now receives the effective driver mode.

## Invariants

- The candidate tour's stop order + polyline (`useTourGeometry(doneResult, state.mode,
  state.loop)`) are **independent** of `presentationMode` (FR-009).
- The driver list mode, and any selected driver's workday-preview mode, always equal the
  effective driver mode.
- No driver stays selected across a mode change (FR-010) — the map shows no preview until a
  driver is (re)selected under the new mode.
- Cancelling either dialog performs no state mutation (FR-004).
