# Quickstart: Dashboard Home Page (feature 028)

## What ships

Root `/` becomes a "Dashboard" launcher for authenticated users (guests still get the welcome page).
Two tiles — "New Tour" (map illustration → `/tour`) and "Manage drivers" (→ `/driver`). The side
panel gains the same two links via the existing `NavMain`.

## Backend (one minimal touch)

1. **`app/Http/Controllers/HomeController.php`** — `index(Request)`:
   - `auth()->check()` → `Inertia::render('home')`
   - else → `Inertia::render('welcome', ['canRegister' => Features::enabled(Features::registration())])`
2. **`routes/web.php`** — replace the root line
   `Route::inertia('/', 'welcome', ['canRegister' => …])->name('home');`
   with `Route::get('/', [HomeController::class, 'index'])->name('home');` (still public, name kept).

## Frontend

3. **`resources/js/pages/home.tsx`** — new Dashboard page (default `AppLayout` → sidebar):
   `<Head title="Dashboard" />`, an `<h1>`-level "Dashboard" heading, and a responsive grid
   (`grid gap-4 sm:grid-cols-2`, stacks on mobile) of two `<DashboardTile>`s:
   - New Tour → `/tour`, with the inline map illustration.
   - Manage drivers → `/driver`.
4. **`resources/js/components/dashboard/dashboard-tile.tsx`** — reusable card: an Inertia `<Link>`
   with the label, optional illustration/icon; role-named palette; hover/focus-visible states;
   `aspect`-based sizing so it reads as a box.
5. **`resources/js/components/app-sidebar.tsx`** — import `NavMain` + two lucide icons; define
   `mainNavItems: NavItem[] = [{ title: 'New Tour', href: '/tour', icon: … }, { title: 'Manage
   drivers', href: '/driver', icon: … }]`; render `<NavMain items={mainNavItems} />` inside the
   (currently empty) `<SidebarContent>`.

## Tests

6. **`tests/Feature/HomePageTest.php`** — authenticated GET `/` → Inertia component `home`; guest GET
   `/` → component `welcome` with `canRegister`.
7. **`resources/js/pages/home.test.tsx`** — renders "Dashboard" heading; exactly two tiles; New Tour
   link `href="/tour"` with a map illustration present; Manage drivers link `href="/driver"`.
8. **`resources/js/components/app-sidebar.test.tsx`** — extend: sidebar shows "New Tour" (→ `/tour`)
   and "Manage drivers" (→ `/driver`) links. (Existing assertions — brand, no starter nav — stay
   green; the removed-starter-nav check targets "Dashboard"/"Repository"/"Documentation", none of
   which we add.)

## Verify

```bash
php artisan test --filter=HomePageTest
npm run test -- home app-sidebar dashboard-tile
npm run format:check && npm run lint:check && npm run types:check
npm run build   # page-render feature tests need a fresh Vite manifest
```

Manual: signed in, open `/` → "Dashboard" + two tiles; New Tour → tour page; Manage drivers →
`/driver`; sidebar shows both links, current one highlighted; sign out, open `/` → welcome unchanged;
resize 320–2560px → no horizontal overflow; tab through tiles + links with the keyboard.

## Guardrails

- No new dependency, no migration, no API, no data.
- `welcome.tsx`, `pages/dashboard.tsx`, and the team `{current_team}/dashboard` route are untouched.
- Role-named palette only (FR-014). Never break the guest welcome flow.
