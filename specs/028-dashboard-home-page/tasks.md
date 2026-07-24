---
description: "Task list for Dashboard Home Page (feature 028)"
---

# Tasks: Dashboard Home Page

**Input**: Design documents from `/specs/028-dashboard-home-page/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/ui-dashboard.md, quickstart.md

**Tests**: INCLUDED — the spec defines an Independent Test per story and the constitution requires
tests for correctness-affecting behavior. Feature test (PHPUnit) for the root-route branch + Vitest
for the page and sidebar.

**Organization**: Grouped by user story. US1 (dashboard page) and US2 (side-panel links) are
independent — either can ship first; there is no shared foundational code beyond setup.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no incomplete dependency)
- **[Story]**: US1 / US2
- Exact file paths included

## Path Conventions

Web app: Laravel backend at repo root (`app/`, `routes/`, `tests/`), React/Inertia frontend at
`resources/js/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the ground rules before touching code.

- [X] T001 Confirm scope from `specs/028-dashboard-home-page/plan.md`: front-only, no new dependency, no migration, no API/data; the `app.tsx` layout resolver gives any non-`welcome` page name the `AppLayout` (sidebar); `NavMain`, `AppLayout`, and the sidebar UI primitives already exist and are reused.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: None required. US1 and US2 touch disjoint files and are independently deliverable/testable.

*(No foundational tasks — proceed to the user stories.)*

---

## Phase 3: User Story 1 - Land on a dashboard and reach the main tasks (Priority: P1) 🎯 MVP

**Goal**: Root `/` shows a "Dashboard" page (authed) with two tiles — "New Tour" (map illustration →
`/tour`) and "Manage drivers" (→ `/driver`); guests still get the welcome page.

**Independent Test**: Signed in, open `/` → "Dashboard" title + two tiles; New Tour → tour page,
Manage drivers → `/driver`. Signed out, open `/` → welcome unchanged. Narrow screen → no overflow.

- [X] T002 [US1] Create `app/Http/Controllers/HomeController.php` — `index(Request)` returns `Inertia::render('home')` when `auth()->check()`, else `Inertia::render('welcome', ['canRegister' => Features::enabled(Features::registration())])` (byte-for-byte the current welcome behaviour).
- [X] T003 [US1] In `routes/web.php`, replace the root line `Route::inertia('/', 'welcome', ['canRegister' => …])->name('home');` with `Route::get('/', [HomeController::class, 'index'])->name('home');` (still public, name `home` kept; add the `use App\Http\Controllers\HomeController;` import) (depends on T002).
- [X] T004 [P] [US1] Create `resources/js/components/dashboard/dashboard-tile.tsx` — a reusable card that is an Inertia `<Link href>` with a visible `label`, an optional `illustration` (ReactNode) and optional `icon`; role-named palette, hover + `focus-visible` states, `aspect`-based box sizing; keyboard-operable + label exposed (it is a real link with text).
- [X] T005 [US1] Create `resources/js/pages/home.tsx` — default `AppLayout` (sidebar). `<Head title="Dashboard" />`, a heading with the exact text "Dashboard", and a responsive grid (`grid gap-4 sm:grid-cols-2`, stacks on mobile, no horizontal overflow) of two `<DashboardTile>`: "New Tour" (href `/tour`, `illustration` = an inline theme-aware map SVG using palette colours) and "Manage drivers" (href `/driver`) (depends on T004).
- [X] T006 [P] [US1] Create `tests/Feature/HomePageTest.php` — authenticated GET `/` renders Inertia component `home`; guest GET `/` renders `welcome` with the `canRegister` prop present (depends on T002, T003).
- [X] T007 [P] [US1] Create `resources/js/pages/home.test.tsx` — mock `@inertiajs/react` (`Head`→null, `Link`→`<a>`); assert the "Dashboard" heading, exactly two tiles, the New Tour link `href="/tour"` with a map illustration (SVG) present, and the Manage drivers link `href="/driver"` (depends on T005).

**Checkpoint**: MVP — the dashboard lands at `/` for authed users and links out; guests unaffected.

---

## Phase 4: User Story 2 - Navigate from the side panel anywhere in the app (Priority: P2)

**Goal**: The side panel lists two links — "New Tour" (→ `/tour`) and "Manage drivers" (→ `/driver`)
— on every authed page, with the current page's link marked active.

**Independent Test**: On any authenticated page open the side panel → both links present; each
navigates to the right page; the current page's link is highlighted.

- [X] T008 [US2] Edit `resources/js/components/app-sidebar.tsx` — import `NavMain` + two lucide icons; define `mainNavItems: NavItem[] = [{ title: 'New Tour', href: '/tour', icon: <route/map icon> }, { title: 'Manage drivers', href: '/driver', icon: <users icon> }]`; render `<NavMain items={mainNavItems} />` inside the (currently empty) `<SidebarContent>`. Brand header, theme selector, and user menu unchanged.
- [X] T009 [US2] Extend `resources/js/components/app-sidebar.test.tsx` — assert the side panel shows a "New Tour" link (→ `/tour`) and a "Manage drivers" link (→ `/driver`); keep the existing assertions green (brand present; no "Dashboard"/"Repository"/"Documentation" starter nav) (depends on T008).

**Checkpoint**: Side-panel links work from anywhere; independently functional.

---

## Phase 5: Polish & Cross-Cutting Concerns

- [X] T010 [P] Run `npm run format:check`, `npm run lint:check`, `npm run types:check` and fix any issue (prettier separate from eslint — run both).
- [X] T011 [P] Run `php artisan test --filter=HomePageTest` and `npm run test -- home app-sidebar dashboard-tile`, then `npm run build` (page-render feature tests need a fresh Vite manifest) — all green.
- [X] T012 Verify role-named palette only (FR-014, incl. the inline map SVG), minimal comments, and no duplicated card rule (`DashboardTile` is the single tile source; sidebar reuses `NavMain`) across the new/edited files.
- [ ] T013 Manual quickstart.md walkthrough: signed in `/` → "Dashboard" + two tiles → correct destinations; signed out `/` → welcome unchanged; sidebar shows both links with the current one highlighted; keyboard-tab through tiles + links; resize 320–2560px → no horizontal overflow.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: none.
- **User Stories (Phase 3–4)**: independent of each other; either can be done first. US1 is the MVP.
- **Polish (Phase 5)**: after the desired stories.

### Within US1

- T002 → T003 (route references the controller). T004 → T005 (page uses the tile). T006 after
  T002/T003; T007 after T005.

### Parallel Opportunities

- US1: T004 (tile) is [P] alongside T002/T003 (backend, different files); tests T006/T007 [P] once
  their targets exist.
- US2 (T008/T009) can proceed fully in parallel with US1 — disjoint files.

---

## Parallel Example: User Story 1

```bash
# Backend branch and the tile component are independent files:
Task: "T002 Create HomeController::index in app/Http/Controllers/HomeController.php"
Task: "T004 Create DashboardTile in resources/js/components/dashboard/dashboard-tile.tsx"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup → Phase 3 US1 → **STOP & VALIDATE**: `/` dashboard for authed, welcome for guests,
   tiles link out.
2. Demo the MVP.

### Incremental Delivery

- US1 (dashboard) and US2 (sidebar links) are independent; add US2 whenever, no coupling.

---

## Notes

- **Guardrail**: `welcome.tsx`, the vendored `pages/dashboard.tsx`, and the team
  `{current_team}/dashboard` route stay untouched. No new dependency, no migration, no API, no data.
- Fixed strings verbatim: title "Dashboard"; labels "New Tour", "Manage drivers".
- The guest welcome flow must remain byte-for-byte unchanged.
- [P] = different files, no incomplete dependency. Commit after each task or logical group.
