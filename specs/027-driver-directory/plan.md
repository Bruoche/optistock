# Implementation Plan: Drivers Directory

**Branch**: `027-driver-directory` | **Date**: 2026-07-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/027-driver-directory/spec.md`

## Summary

A new authenticated page at route `/driver` (singular — coherent with the existing `tour` /
`driver/{driver}` routes, distinct from the `/driver/{driver}` management page) that lists every
manageable driver — avatar/placeholder,
name, delivery-mode icons, assigned warehouse — presented like the tour-assignment driver row but
**without** any workday/road figures, sorted alphabetically, each row linking to that driver's
existing management page (`/driver/{id}`, feature 025). A criteria bar above the list offers three
filters — partial case-insensitive name search, a required-modes multi-selector (AND semantics),
and an optional warehouse — that re-filter the list dynamically.

Per the user's directive for this feature, filtering is done by **one new backend endpoint**
`GET /api/drivers` that returns the matching drivers for the supplied criteria. It is entirely
additive: no existing route, controller method, service, request, or payload changes. The frontend
re-fetches this endpoint (debounced, with stale-request cancellation) whenever a criterion changes,
so the list always settles on the latest criteria.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 12), TypeScript 5 / React 19 (Inertia 2)

**Primary Dependencies**: Laravel Eloquent, Inertia, Tailwind v4, lucide-react; no new dependency

**Storage**: MySQL/SQLite via Eloquent — existing `drivers`, `warehouses`, `delivery_modes`,
`driver_delivery_mode` tables. No migration, no schema change.

**Testing**: PHPUnit (feature test for the endpoint), Vitest + Testing Library (hook + components)

**Target Platform**: Web (desktop + mobile ≥320px)

**Project Type**: Web application (Laravel backend + Inertia/React frontend)

**Performance Goals**: any-criterion change reflected within ~300ms for already-loaded data
(SC-004) via input debounce over a bounded driver set; no full page reload

**Constraints**: no impact to existing endpoints (new route only); role-named palette only (FR-016);
no horizontal overflow 320–2560px (FR-014); empty-state text verbatim
`no drivers found with current criterias.`

**Scale/Scope**: 1 new API endpoint, 1 new web route, small backend slice (controller + request +
service + DTO), 1 new page + criteria bar + hook, one shared presentational extraction. Dozens of
drivers expected — a plain indexed query, no pagination needed.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Quality First** — PASS. New endpoint covered by a feature test (name/modes-AND/warehouse/
  combined/empty); hook + bar + shared row covered by Vitest. Full CI gate (prettier `format:check`,
  eslint, types, PHPUnit, Vitest) run before done.
- **II. Readable by Default** — PASS. Verb methods / noun classes, full words ("Tour" not "Route"),
  minimal comments reserved for non-inferable constraints (e.g. the case-insensitive `LOWER(...) LIKE`
  choice). Intent-named `search`/`matching` query builders.
- **III. Simple & Transparent** — PASS. Thin controller → single-responsibility
  `DriverDirectoryService` builds one Eloquent query from validated criteria → DTO shapes the row.
  Simplest solution: filter in the DB, no counterfactuals, no routing calls (unlike `available`).
- **IV. Robustness as Standard** — PASS. `DriverDirectoryRequest` validates every criterion (invalid
  mode / unknown warehouse rejected). No silent empty list: the frontend distinguishes loading /
  error / no-match. Endpoint reads only; nothing to log-and-swallow.
- **V. Performance with Clarity** — PASS. Filtering pushed to an indexed query; debounced client
  fetch with cancellation avoids stale/flicker. No N+1 — `deliveryModes`/`warehouse` eager-loaded.
- **VI. Consistent, Reusable Front-End Styling** — PASS. Role-named palette classes only; the row's
  identity block (avatar + name + mode icons + warehouse) is **extracted** into a shared
  presentational component reused by both the assignment list and the directory (no duplicated
  visual rule). Criteria bar wraps on narrow screens.

No violations — Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/027-driver-directory/
├── plan.md              # This file
├── spec.md              # Feature spec
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── drivers-directory.md   # GET /api/drivers contract
└── tasks.md             # Phase 2 (/speckit-tasks — not created here)
```

### Source Code (repository root)

```text
app/
├── Http/
│   ├── Controllers/
│   │   ├── DriverDirectoryController.php   # NEW — thin index() → service
│   │   └── DriverPageController.php        # +directory() method (manage() byte-for-byte unchanged)
│   └── Requests/
│       └── DriverDirectoryRequest.php      # NEW — name?/modes[]?/warehouse? validation
├── Services/
│   └── DriverDirectoryService.php          # NEW — builds the filtered query, returns rows
├── DTOs/
│   └── DirectoryDriverData.php             # NEW — one directory row's payload shape
└── Models/
    └── Driver.php                          # +scopeMatching(name, modes, warehouseId) query scope

routes/
├── api.php                                 # + GET drivers → drivers.index (list endpoint, new line only)
└── web.php                                 # + GET driver → driver.directory.page (page route, new line only)

resources/js/
├── pages/driver/
│   └── directory.tsx                       # NEW — page: criteria bar + list + states
├── components/driver/
│   ├── directory-bar.tsx                   # NEW — name field + modes multiselect + warehouse select
│   └── driver-summary.tsx                  # NEW — shared avatar+name+modes+warehouse block
├── components/tour/
│   └── driver-list.tsx                     # uses <DriverSummary> for its identity block (figures kept)
├── hooks/
│   └── use-drivers-directory.ts            # NEW — debounced fetch + cancel + settle-on-latest
└── types/
    └── driver.ts                           # + DirectoryDriver type (reuses WarehouseOption)
```

**Structure Decision**: Existing Laravel + Inertia/React web app. The backend slice follows the
established Controller → Request → Service → DTO layering already used by `DriverController::day`
(`DayWorkdayService` / `DriverDayData`); the filter itself lives in a Driver query scope beside the
existing `scopeAvailable`. Frontend follows feature 025's `pages/driver/*` + `components/driver/*` +
`hooks/use-*` layout, and factors the shared row identity out of `driver-list.tsx` to avoid
duplicating the presentation the spec says must match.

## Complexity Tracking

No constitution violations — table omitted.
