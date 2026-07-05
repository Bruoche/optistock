# Data Model: Mobile Responsive Interface

**No data.** This feature is a presentation/layout change only — it adds no entities, fields, persisted state, request payloads, or API surface. There is no schema, migration, or serialization impact.

## Style artifact (not data, documented for traceability)

### `scroll-x-contained` utility (`resources/css/app.css`)
- **Purpose**: contain a bar's horizontal overflow inside its own box and make it scrollable when it overflows.
- **Declarations**: `overflow-x: auto;` (+ `overscroll-behavior-x: contain;`).
- **Applied to**: the root of `TourControlBar` and the header bar of `ResultSummary`.
- **Invariant**: when contents fit, produces no visual change (no scrollbar, identical layout); when they overflow, the bar scrolls horizontally and clips to its rounded box.
- **Overflow-only by design**: the utility declares nothing but overflow/overscroll — no display, padding, margin, or sizing — so it structurally cannot deform the fits-state. Keep it that way; it is the reason no automated fits-state guard is needed (and none is possible in jsdom).
- **Reuse preconditions**: single-row / no intended vertical overflow, and a container-constrained width — see `contracts/responsive-bars.md`.

### Bar child-group sizing
- Each bar's immediate child groups carry `shrink-0` so controls keep intrinsic width and the bar scrolls rather than compressing controls. No effect on the fits-wide layout.
