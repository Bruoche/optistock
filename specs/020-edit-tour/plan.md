# Implementation Plan: Edit Tour

**Branch**: `020-edit-tour` | **Date**: 2026-07-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/020-edit-tour/spec.md`

## Summary

Let a planner re-open an already-optimized (but unassigned) tour in the optimization menu, adjust its stops/options, and re-optimize so the **existing** tour record is updated in place instead of a duplicate being created. The result view's action row becomes **New · Edit · Assign** (the first two renamed from "New tour"/"Assign Driver"). The Edit button navigates to the same optimization page carrying a tour id as a path parameter; the page hydrates every control (stops, per-stop durations, mode, loop) from that tour so the edit is reliable. The optimize endpoint gains an optional `tour_id` that switches the persistence step from *create* to *update*, threaded through the existing cache-hit and queued-job paths with no new routing calls and no schema change.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 12) backend; TypeScript 5 + React 19 (Inertia) frontend

**Primary Dependencies**: Laravel, Inertia.js, react-map-gl/maplibre, Laravel Reverb (broadcast), existing OpenStreet TSP client

**Storage**: MySQL — existing `tours` + `stops` tables. **No migration**: editing reuses the same rows (update tour, replace its stops). Date stays on the `driver_tour` pivot and is not part of an unassigned tour.

**Testing**: Pest (PHP: Feature + Unit), Vitest + Testing Library (TS)

**Target Platform**: Containerized web app (feature 008)

**Project Type**: Web application (Laravel backend + Inertia/React frontend, single repo)

**Performance Goals**: Editing adds no routing/TSP calls beyond a normal optimize; hydration of the edit page is one indexed tour+stops read.

**Constraints**: Impact code as little as possible and avoid duplication — reuse the optimize pipeline, request, service, recorder, and the optimize page rather than forking them. Editing is allowed only while the tour is unassigned (FR-009).

**Scale/Scope**: Small. ~1 new web controller, 1 new page prop path, additive `tour_id` through 4 backend files, button relabel + Edit navigation, hook the optimize page to hydrate from a prop.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Quality First**: Additive `tour_id` threading with tests for the update-in-place path (Feature test: optimize with `tour_id` mutates the same row, count unchanged; hydration returns the tour's stops/options). PASS.
- **II. Readable by Default**: No new narration; the create-vs-update branch in `TourRecorder` is a single clear conditional. One in-body comment justified only where the "update = replace stops in a transaction" invariant is non-obvious. PASS.
- **III. Simple & Transparent**: Reuses the existing pipeline; the only decision point is create vs update at the persistence step. No new endpoint for optimize; a single new read-only page controller. PASS.
- **IV. Robustness as Standard**: `tour_id` is validated for ownership + unassigned (foreign/assigned id → 404, matching the assignment request pattern). A tour that vanishes before the queued job's update surfaces as `persist_failed` and is logged (no silent create). PASS.
- **V. Performance with Clarity**: No extra upstream calls; hydration is one eager-loaded query. PASS.
- **VI. Consistent, Reusable Front-End Styling**: Buttons reuse `ActionButton`; no new colors. PASS.

No violations — Complexity Tracking left empty.

## Project Structure

### Documentation (this feature)

```text
specs/020-edit-tour/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── edit-tour.md     # Phase 1 output
└── tasks.md             # Phase 2 output (/speckit-tasks)
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/
│   │   ├── TourOptimizationController.php   # pass validated tour_id into the service
│   │   └── TourPageController.php           # NEW: render optimize page for new + edit
│   └── Requests/
│       └── OptimizeTourRequest.php          # add optional owned+unassigned tour_id rule
├── Jobs/
│   └── OptimizeTourJob.php                  # carry editTourId → recorder
├── Models/
│   ├── Tour.php                             # (read) assignment check helper if needed
│   └── Stop.php
└── Services/
    ├── TourOptimizationService.php          # optimize(..., ?int $editTourId); thread it
    └── TourRecorder.php                     # record(..., ?int $editTourId): update vs create

routes/
└── web.php                                  # tour page + tour/{tour}/edit via controller

resources/js/
├── pages/tour/optimize.tsx                  # read editTour prop; hydrate + editTourId
├── hooks/use-tour-optimization.ts           # seed stops/editTourId; send tour_id on optimize
├── components/tour/result-summary.tsx       # New · Edit · Assign; Edit → router.visit
└── types/tour.ts                            # EditTour type + optimize payload tour_id

tests/
├── Feature/                                 # optimize-with-tour_id + edit-page hydration
└── Unit/                                     # TourRecorder update path
resources/js/**/*.test.tsx                    # button row + hydration wiring
```

**Structure Decision**: Existing single-repo web application layout. The optimize *page* is reused (one controller now supplies its props for both new and edit); the optimize *endpoint* is reused (optional `tour_id`). The only new file is `TourPageController`; everything else is an additive edit to a file already in the pipeline.

## Complexity Tracking

> No constitution violations — section intentionally empty.
