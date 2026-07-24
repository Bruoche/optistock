# Contract: Dashboard Home Page & Side-Panel Links (UI)

Front-only feature. No API endpoint, no payload. The "contracts" here are the root-route behaviour
and the two UI surfaces.

## Root route `GET /` (name `home`, public)

`HomeController::index` branches by authentication:

| Caller           | Inertia page rendered | Props                                   | Layout (via app.tsx) |
|------------------|-----------------------|-----------------------------------------|----------------------|
| Authenticated    | `home`                | none                                    | `AppLayout` (sidebar) |
| Guest (no auth)  | `welcome`             | `canRegister` (unchanged current value) | none (bare)          |

Guarantees:
- The guest branch is byte-for-byte the current welcome behaviour (welcome flow unchanged).
- No client-side flash: the correct page is chosen server-side.
- Route stays public and keeps the `home` name; no middleware change.

## Dashboard page (`pages/home.tsx`, authed)

- Renders a heading with the exact text **"Dashboard"** at the top; document `<title>` is "Dashboard".
- Renders exactly two `DashboardTile`s in a responsive grid (two columns on wide screens, stacked on
  narrow), no horizontal overflow 320–2560px:
  - **New Tour** — includes an inline map illustration; links to `/tour`.
  - **Manage drivers** — links to `/driver`.
- Each tile is a real link (keyboard-focusable, activatable, label exposed to assistive tech).
- All colours from the role-named palette; no one-off values.

## Side panel (`app-sidebar.tsx` → `NavMain`)

- The side panel (present on all authed `AppLayout` pages) lists two links, in order:
  1. **New Tour** → `/tour`
  2. **Manage drivers** → `/driver`
- The link matching the current page is marked active (`NavMain` via `useCurrentUrl`).
- Links are keyboard-operable and labelled; brand header, theme selector, and user menu are unchanged.

## Out of scope / frozen

- The vendored `pages/dashboard.tsx` and the team `{current_team}/dashboard` route are untouched.
- `welcome.tsx` is untouched (only the root route's guest branch reuses it).
- No new data, statistics, widgets, or endpoints.
