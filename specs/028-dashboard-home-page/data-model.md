# Phase 1 Data Model: Dashboard Home Page

No persistence, no migration, no API. This feature has no data entities — only front-end view models
for navigation. Documented here for completeness.

## View models (front-end only)

### DashboardTile (component props)

The reusable dashboard card.

| Field          | Type                 | Notes                                                        |
|----------------|----------------------|-------------------------------------------------------------|
| `label`        | string               | Visible tile label, e.g. "New Tour", "Manage drivers".      |
| `href`         | string               | Destination (`/tour`, `/driver`). A plain client-side link. |
| `illustration` | ReactNode (optional) | Inline SVG map for "New Tour"; omitted for "Manage drivers".|
| `icon`         | LucideIcon (optional)| Optional label icon (e.g. Users for "Manage drivers").      |

### NavItem (existing type, reused)

`resources/js/types/navigation.ts` — `{ title: string; href; icon?: LucideIcon | null; isActive? }`.
The side-panel items are two `NavItem`s:

- `{ title: 'New Tour', href: '/tour', icon: <route/map icon> }`
- `{ title: 'Manage drivers', href: '/driver', icon: <users icon> }`

`NavMain` derives the active item from the current URL (`useCurrentUrl`) — no `isActive` passed.

## Root-route branch (server-side, no data)

`HomeController::index(Request)`:

- authenticated → `Inertia::render('home')` (dashboard; no props needed)
- guest → `Inertia::render('welcome', ['canRegister' => Features::enabled(Features::registration())])`
  — byte-for-byte the current welcome behaviour.

## Fixed strings (verbatim from spec)

- Page title: `Dashboard`
- Tile / link labels: `New Tour`, `Manage drivers`
- Destinations: `New Tour` → `/tour` (tour-planning page), `Manage drivers` → `/driver`
  (drivers directory, feature 027).

## States / transitions

Stateless. The dashboard renders immediately (no fetch). Navigation is a normal client-side visit.
The side panel highlights whichever of its two links matches the current page (FR-013).
