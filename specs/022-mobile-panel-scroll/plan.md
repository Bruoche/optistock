# Implementation Plan: Mobile Scrollable Content Panel

**Branch**: `022-mobile-panel-scroll` | **Date**: 2026-07-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/022-mobile-panel-scroll/spec.md`

## Summary

On phone-width screens, turn the tour screen's bottom content panel into a single scroll surface so the (now tall, wrapped) orange bar can be scrolled up and out of the way, revealing the full driver / stop list. The bar slides up and is clipped at the panel's top edge — directly beneath the fixed map — so it "disappears behind the map" with no overlap. Also drop the panel's outer padding on mobile so the orange bar sits edge-to-edge with no framing background border. Every change is layered with Tailwind's `max-md:` variant (viewport < 768px, the app's own mobile breakpoint), so it is purely additive and the desktop layout's existing classes are left literally untouched.

## Technical Context

**Language/Version**: TypeScript 5 + React 19 (Inertia); Tailwind CSS v4 (`max-*` responsive variants)

**Primary Dependencies**: Tailwind v4; existing tour components. No JS libraries added.

**Storage**: N/A — presentation/layout only, no data, no backend

**Testing**: Vitest + Testing Library. Viewport/scroll behavior is not observable in jsdom (no layout engine / no media queries), so automated coverage asserts the `max-md:` override classes are present on the right elements; the actual scroll + edge-to-edge behavior is verified manually via the quickstart.

**Target Platform**: Mobile + desktop browsers (containerized web app, feature 008)

**Project Type**: Web application (Inertia/React frontend); frontend/CSS only

**Performance Goals**: No runtime cost — pure CSS media-query variants; no JS, no viewport listeners, no re-render changes

**Constraints** (from the user): mobile-only via conditional CSS; desktop interface must be provably unaffected; change must be simple, minimal, and clean.

**Scale/Scope**: Tiny. `max-md:` utilities added to ~5 elements across 5 files: the panel, the two content wrappers, the two lists, and the two bars.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Quality First**: Small, additive CSS; guarded by class-presence tests + a manual quickstart. PASS.
- **II. Readable by Default**: Each override is a standard Tailwind variant utility expressing one intent; no narration. PASS.
- **III. Simple & Transparent**: `max-md:` variants layered on top of the current classes — the simplest way to scope to mobile without a JS breakpoint hook or rewriting the desktop classes. PASS.
- **IV. Robustness as Standard**: No logic/failure paths added. Behavior is a continuous CSS media query. PASS.
- **V. Performance with Clarity**: Pure CSS; zero runtime overhead. PASS.
- **VI. Consistent, Reusable Front-End Styling**: No new colors; uses standard utilities. The two structurally-identical content states (result / editing) receive the same override set, keeping behavior consistent. `max-md:rounded-none` on the two bars is a standard utility, not a bespoke duplicated rule. PASS.

No violations — Complexity Tracking empty.

## Project Structure

### Documentation (this feature)

```text
specs/022-mobile-panel-scroll/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output (documents "no data")
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── mobile-panel.md  # Phase 1 output (UI contract: the mobile override set)
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
resources/js/pages/tour/
└── optimize.tsx                             # panel: max-md:overflow-y-auto + max-md:p-0

resources/js/components/tour/
├── result-summary.tsx                       # root: max-md:h-auto; header bar: max-md:rounded-none
├── driver-list.tsx                          # <ul>: max-md:flex-none
├── stop-list.tsx                            # root: max-md:h-auto; <ul>: max-md:flex-none
└── tour-control-bar.tsx                     # bar: max-md:rounded-none

resources/js/components/tour/                 # tests (class-presence guards)
├── result-summary.test.tsx
├── driver-list.test.tsx
├── stop-list.test.tsx
└── tour-control-bar.test.tsx
```

**Structure Decision**: Existing single-repo web app, frontend only. No new files — all four target test files already exist and gain one assertion each. The desktop classes stay exactly as they are; mobile behavior is expressed solely through added `max-md:` variants.

## Complexity Tracking

> No constitution violations — section intentionally empty.
