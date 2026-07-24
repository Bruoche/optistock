# Phase 0 Research: Dashboard Home Page

No `NEEDS CLARIFICATION` remained after the spec + user directive. Decisions below.

## Decision 1 — How the authed dashboard lands at `/` (the one route touch)

- **Decision**: Replace the root route `Route::inertia('/', 'welcome', [...])` with a thin
  `HomeController::index` that renders the new `home` (dashboard) page when the request is
  authenticated, and the existing `welcome` page (with `canRegister`) when it is not. Route stays
  public and keeps the name `home`.
- **Rationale**: The `app.tsx` layout resolver keys the layout off the Inertia page name — `welcome`
  → `null` (bare guest landing), any other name → `AppLayout` (the sidebar chrome). The dashboard
  must carry the sidebar (US2/FR-010), so it cannot be the `welcome` page; it needs its own page
  name. Branching server-side picks the right page (and thus the right layout) with no client-side
  flash of the wrong page. This is the minimal change consistent with "front-only" — no data, no API.
- **Alternatives considered**:
  - *Render dashboard content inside `welcome.tsx` for authed users.* Rejected — `welcome` resolves
    to the null layout, so it would have no sidebar; and `welcome.tsx` is vendored starter code best
    left untouched.
  - *A route closure instead of a controller.* Works, but the project favours thin controllers
    (Controller→… roles) and a controller is trivially testable.
  - *Put the dashboard behind `auth` middleware at `/`.* Rejected — `/` must still serve guests the
    welcome page; branching in the controller keeps one public root.

## Decision 2 — New page name `home` (not reusing `dashboard`)

- **Decision**: New page `resources/js/pages/home.tsx`. Leave the vendored `pages/dashboard.tsx` and
  the team-prefixed `{current_team}/dashboard` route untouched.
- **Rationale**: `pages/dashboard.tsx` is starter scaffolding bound to the team dashboard route;
  repurposing it would change that route's output (a regression risk) and couple two unrelated
  screens. A distinct `home` page keeps the feature additive. `home` resolves to `AppLayout` via the
  resolver's default branch, giving the sidebar for free. Head title is "Dashboard".
- **Alternatives considered**: Overwrite `dashboard.tsx` (couples/regresses the team route);
  name the page `dashboard` (collides with the existing page file).

## Decision 3 — Reuse `NavMain` for the side-panel links

- **Decision**: In `app-sidebar.tsx`, render the existing `<NavMain items={mainNavItems} />` inside
  the currently-empty `SidebarContent`, with `mainNavItems = [{ title: 'New Tour', href: '/tour',
  icon: … }, { title: 'Manage drivers', href: '/driver', icon: … }]`.
- **Rationale**: `NavMain` is the reusable side-panel list the feature references — it already renders
  a `SidebarMenu` of `NavItem`s and marks the current page active via `useCurrentUrl` (satisfies
  FR-008/FR-011/FR-013 with no new component). One edit to the shared `AppSidebar`, so every instance
  of the side panel gets the links.
- **Alternatives considered**: Hand-rolling a new `SidebarMenu` inline (duplicates what `NavMain`
  already does — violates Constitution VI).

## Decision 4 — The "New Tour" map illustration as inline SVG

- **Decision**: Render the map on the "New Tour" tile as an inline, theme-aware SVG illustration
  (role-palette `currentColor`/palette classes), not a raster `<img>` asset.
- **Rationale**: Keeps the asset self-contained and themeable in light/dark (FR-014), and it always
  renders — so the "broken image" edge case (FR-012) is satisfied by construction, no fallback path
  needed. No binary asset to add or cache-bust.
- **Alternatives considered**: A static raster in `public/` (off-palette, not theme-aware, needs a
  broken-image fallback for FR-012); a live/interactive map (out of scope — the tile is a launcher,
  and the spec's map is illustrative per the Assumptions).

## Decision 5 — Two tiles share one `DashboardTile` component

- **Decision**: A `DashboardTile` presentational component (an Inertia `<Link>` card taking a label,
  href, and optional illustration/icon), instantiated twice.
- **Rationale**: The two tiles share the same card rule; one component prevents duplicating it
  (Constitution VI) and keeps the grid responsive (stacks on mobile, FR-007). Keyboard-operable and
  labelled because it is a real link with visible text (FR-011).
- **Alternatives considered**: Two bespoke cards (duplicated visual rule).
