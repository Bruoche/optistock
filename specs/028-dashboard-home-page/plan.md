# Implementation Plan: Dashboard Home Page

**Branch**: `028-dashboard-home-page` | **Date**: 2026-07-24 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/028-dashboard-home-page/spec.md`

## Summary

Turn the application root (`/`) into a "Dashboard" launcher for authenticated users, replacing the
starter welcome page as the authed landing. The dashboard shows a "Dashboard" title and two
navigation tiles — "New Tour" (with a map illustration) linking to the tour-planning page (`/tour`)
and "Manage drivers" linking to the drivers directory (`/driver`, feature 027). The side panel gains
a two-item nav list ("New Tour", "Manage drivers") reusing the existing `NavMain` component.

Front-only feature with one unavoidable, minimal route touch: the root `/` route branches so
authenticated users get the new dashboard page (which resolves to `AppLayout` → sidebar) while guests
keep the existing `welcome` page unchanged. No new data, no API, no migration; the tiles and links are
plain links to existing pages.

## Technical Context

**Language/Version**: PHP 8.4 (Laravel 12), TypeScript 5 / React 19 (Inertia 2)

**Primary Dependencies**: Inertia, Tailwind v4, lucide-react; no new dependency

**Storage**: N/A — no data read or written

**Testing**: PHPUnit (root-route branching), Vitest + Testing Library (page + sidebar)

**Target Platform**: Web (desktop + mobile ≥320px)

**Project Type**: Web application (Laravel backend + Inertia/React frontend)

**Performance Goals**: instant navigation (plain client-side links, no fetch)

**Constraints**: role-named palette only (FR-014); no horizontal overflow 320–2560px (FR-007);
keyboard-operable + labelled for assistive tech (FR-011); guest welcome flow unchanged.

**Scale/Scope**: 1 new thin controller + 1 root-route change, 1 new page, 1 reusable tile component,
1 sidebar edit reusing `NavMain`. Launcher only — no widgets/stats.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Quality First** — PASS. Root branching covered by a feature test (authed→dashboard,
  guest→welcome); page + sidebar covered by Vitest. Full CI gate before done.
- **II. Readable by Default** — PASS. Thin `HomeController::index`; a small `DashboardTile`
  presentational component; intent-named props. Minimal comments.
- **III. Simple & Transparent** — PASS. Simplest solution: one route branch to place an authed page
  at `/`; tiles/links are plain `<Link>`s. No state, no data layer.
- **IV. Robustness as Standard** — PASS. Guest vs authed decided server-side (no client flash of the
  wrong page). The map illustration is inline SVG, so it always renders (FR-012 satisfied by
  construction — no broken-image path). Nothing to log (read-only navigation).
- **V. Performance with Clarity** — PASS. No fetch; client-side links.
- **VI. Consistent, Reusable Front-End Styling** — PASS. The two tiles share one `DashboardTile`
  component (no duplicated card rule); the sidebar reuses the existing `NavMain` (no bespoke nav);
  all colours from role-named palette; responsive grid stacks on mobile.

No violations — Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/028-dashboard-home-page/
├── plan.md              # This file
├── spec.md
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/
│   └── ui-dashboard.md  # UI/route contract
└── checklists/requirements.md
```

### Source Code (repository root)

```text
app/Http/Controllers/
└── HomeController.php            # NEW — index() branches authed→dashboard page, guest→welcome

routes/
└── web.php                      # root `/` route: Route::inertia(...'welcome') → [HomeController,'index'] (name 'home' kept, still public)

resources/js/
├── pages/
│   └── home.tsx                 # NEW — the Dashboard page (title + two tiles); default AppLayout → sidebar
├── components/
│   ├── dashboard/
│   │   └── dashboard-tile.tsx   # NEW — reusable tile: label, href, optional illustration
│   └── app-sidebar.tsx          # EDIT — render <NavMain items={mainNavItems}> in SidebarContent

tests/Feature/
└── HomePageTest.php             # NEW — authed→'home' component, guest→'welcome' component
resources/js/pages/home.test.tsx             # NEW — title, two tiles, correct hrefs, map illustration
resources/js/components/app-sidebar.test.tsx # EDIT — asserts the two nav links + hrefs
```

**Structure Decision**: Existing Laravel + Inertia/React app. The page lands via the layout resolver
in `app.tsx` (`welcome` → null layout for guests; any other page name → `AppLayout` with the sidebar),
so the authed dashboard must be a page name other than `welcome` — hence a new `home` page and a root
route that server-side-branches which page to render. The sidebar reuses the already-present
`NavMain` component (the "common side-panel component" the feature references), and the two tiles are
one shared `DashboardTile` to avoid duplicating the card presentation.

## Complexity Tracking

No constitution violations — table omitted.
