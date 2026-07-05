# Implementation Plan: Mobile Responsive Interface

**Branch**: `021-mobile-responsive` | **Date**: 2026-07-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/021-mobile-responsive/spec.md`

## Summary

Make the tour optimization screen's two main bars — the editing-view control bar (`TourControlBar`) and the result-view summary/action bar (`ResultSummary` header) — contain their own overflow and scroll horizontally when their contents are wider than the viewport, instead of spilling out of the rounded bar and being clipped unreachable by the surrounding `overflow-hidden` column. The behavior is always-on and viewport-driven: when contents fit, nothing changes (identical look, no scrollbar); when they overflow, the bar becomes a horizontally scrollable strip that keeps every control inside its visual box and reachable. Delivered as a single reusable style so both bars share one source of truth (Constitution VI).

## Technical Context

**Language/Version**: TypeScript 5 + React 19 (Inertia); Tailwind CSS v4 (`@theme`, `@utility`)

**Primary Dependencies**: Tailwind v4, existing shadcn/Radix UI components (Select portals to body; the date field is a native `<input type="date">`)

**Storage**: N/A — presentation-only change, no data, no backend

**Testing**: Vitest + Testing Library. Note: CSS overflow/scroll is not observable in jsdom (no layout engine), so automated coverage is limited to asserting the shared scroll style is applied to each bar's root; the visual scroll behavior is verified via the quickstart on a narrow viewport.

**Target Platform**: Mobile + desktop browsers (containerized web app, feature 008)

**Project Type**: Web application (Inertia/React frontend); this feature is frontend/CSS only

**Performance Goals**: No runtime cost — pure CSS overflow; no JS, no layout thrash, no re-render changes

**Constraints**: The non-overflowing state MUST look exactly as today (user directive); the fix must be always-available (not gated behind a JS breakpoint/toggle); overflowing controls must stay inside their visual box.

**Scale/Scope**: Tiny. One new shared utility in `app.css` + a class on each of the two bars (and `shrink-0` on their child groups so controls keep intrinsic width instead of squishing before they scroll).

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Quality First**: Change is small and CSS-only; guarded by tests asserting the shared scroll style is present on both bars, plus a quickstart visual check. PASS.
- **II. Readable by Default**: One well-named utility (`scroll-x-contained`) expresses intent; no narration. PASS.
- **III. Simple & Transparent**: Uses native CSS `overflow-x: auto` — the simplest mechanism that keeps the fits state unchanged and scrolls on overflow. No JS, no viewport listeners. PASS.
- **IV. Robustness as Standard**: No failure paths introduced (no logic). Behavior is continuous across all widths. PASS.
- **V. Performance with Clarity**: Pure CSS; zero runtime overhead. PASS.
- **VI. Consistent, Reusable Front-End Styling**: The scroll behavior is factored into ONE reusable utility applied at both bars — a single-point change, no duplicated bespoke rule, no new colors. PASS. (This is the central principle for this feature.)

No violations — Complexity Tracking empty.

## Project Structure

### Documentation (this feature)

```text
specs/021-mobile-responsive/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output (documents "no data")
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── responsive-bars.md   # Phase 1 output (UI contract)
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
resources/css/
└── app.css                                  # add @utility scroll-x-contained (single source)

resources/js/components/tour/
├── tour-control-bar.tsx                     # apply scroll-x-contained to bar root; shrink-0 on child groups
└── result-summary.tsx                       # apply scroll-x-contained to header bar; shrink-0 on figures + buttons

resources/js/pages/tour/
└── optimize.tsx                             # verify no page-level horizontal overflow (already clipped by overflow-hidden column)

resources/js/components/tour/
├── tour-control-bar.test.tsx                # NEW: asserts the scroll style is on the bar root
└── result-summary.test.tsx                  # add assertion: scroll style on the header bar root
```

**Structure Decision**: Existing single-repo web app. Frontend-only. The one shared style lives in `app.css`; the two bar components each opt in with the same class, keeping the scroll rule single-sourced.

## Complexity Tracking

> No constitution violations — section intentionally empty.
