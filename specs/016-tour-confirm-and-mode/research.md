# Research — New-Tour Confirmation & Presentation-Layer Mode Selector

Frontend-only feature. No backend, no endpoints, no migrations. Both edits wire existing
pieces (`Dialog` primitive, `ModeSelect`, `useTourDrivers`, `useWorkdayPreview`) into the
result view (`ResultSummary` + `optimize.tsx`).

## Existing slice (reused as-is)

- **`AssignDriverDialog`** (`resources/js/components/tour/assign-driver-dialog.tsx`) — the
  confirm pop-up pattern to mirror: shadcn `Dialog` + `DialogHeader/Title/Description/Footer`,
  a `Cancel` outline button and a `Confirm` button. The new-tour pop-up is the same shape with
  different copy and a synchronous confirm.
- **`ModeSelect`** (`resources/js/components/tour/mode-select.tsx`) — the trucking/driving/
  walking dropdown. Already styled for a `bg-primary text-text-on-color` bar; `ResultSummary`'s
  header is exactly that, so it drops in with no new styling.
- **`useTourDrivers(mode, date, tourId)`** — already refetches on `mode` change and never shows
  a stale list (returns `loading` until the fetch for the current key resolves). Feeding it a
  presentation-selected mode gives FR-008/FR-011 for free.
- **`useWorkdayPreview(driver, mode, cacheKey)`** — the selected driver's workday legs, traced
  for `mode`. Passing the presentation mode keeps a re-selected driver's preview correct.
- **`ResultSummary` / `optimize.tsx`** — the result view already lifts `selectedDriver` to the
  page and clears it on reset/date-change (feature 014). Mode change reuses the same clear.

## Decisions

- **D1 — Extract a shared `ConfirmDialog`.** Both pop-ups have an identical structure
  (header + title + description + Cancel/Confirm footer, `pending`-aware). Per constitution
  III/VI (no duplicate logic, shared abstractions), factor a presentational
  `ConfirmDialog({ open, onOpenChange, title, description, confirmLabel?, pending?, onConfirm })`
  and have **both** `AssignDriverDialog` (keeps its `useAssignDriver` logic) and the new-tour
  confirmation render it. The user's "reuse and copy the existing pop-up" is satisfied by a
  single shared component rather than a literal copy.
  - *Rejected*: duplicating the `Dialog` markup a second time in `ResultSummary` — copy-paste
    the constitution forbids, and two pop-ups to keep visually in sync.

- **D2 — New-tour confirm lives in `ResultSummary`, gates `onReset`.** "New tour" no longer
  calls `onReset` directly; it opens `ConfirmDialog`. Confirm → `onReset` (today's exact
  outcome). Cancel/dismiss → close only, zero side effects (FR-004). Synchronous, no hook,
  no `pending`. Copy: title **"Make a new tour?"**, body **"Are you sure you want to make a new
  tour? The tour will remain unassigned."** (user-supplied), confirm label **"Confirm"**
  (matches the assignment dialog).
  - *Rejected*: a native `window.confirm` — off-brand, unstyled, unmockable in tests.

- **D3 — Presentation mode = page state defaulting to the tour's optimization mode.** Add
  `presentationMode: DeliveryMode | null` to `optimize.tsx`; `null` means "follow the tour's
  optimization mode". The **effective driver mode** = `presentationMode ?? state.mode`. Passed
  to `ResultSummary` → `DriverList` and to `useWorkdayPreview`. Selecting a mode sets it and
  clears `selectedDriver` (FR-010); `resetTour` sets it back to `null` so the next optimized
  tour starts at its own optimization mode (FR-007).
  - *Rejected*: local state inside `ResultSummary` — the mode also feeds the map preview
    (`useWorkdayPreview`) and the cleared selection lives on the page, so page state is the
    single source (matches 014's `selectedDriver` lift).

- **D4 — Candidate tour geometry stays on `state.mode` (FR-009).** `useTourGeometry` keeps
  taking `state.mode`/`state.loop`; only the driver list + a re-selected driver's preview
  follow the presentation mode. This is the whole point of the "presentation-layer only" edit —
  no re-optimize, no re-trace, no extra optimization call.

- **D5 — Preview cache key gains the mode.** `useWorkdayPreview`'s cache key becomes
  `${doneResult?.id}:${tourDate}:${effectiveDriverMode}` so switching mode (then selecting a
  driver) can't serve a preview traced for the previous mode.

## No open questions

FR-009 resolved (Option A) in the spec. No NEEDS CLARIFICATION remains.
