# Implementation Plan: Header Brand & Theme Menu

**Branch**: `005-header-theme-menu` | **Date**: 2026-06-09 | **Spec**: [spec.md](spec.md)

## Summary

Rebrand the starter-kit sidebar to Optistock and replace its leftover content with a single theme
selector. Keep the `Sidebar` skeleton + the brand row (logo placeholder + title); change the title from
"Laravel Starter Kit" to **Optistock**; remove the starter-kit content (team switcher, nav items, footer
links) while keeping the functional account/auth user menu; and surface the **existing** appearance mechanism (`useAppearance`: light / dark /
system, default system) as a compact in-sidebar selector. "Browser" = the existing `system` value — the
standard Laravel starter-kit theming method is reused as-is; no new theme engine, no backend change.

## Technical Context

**Stack**: React 19 + Inertia + Tailwind v4 + shadcn/ui (frontend only — no Laravel backend change).

**Standard theming method (reused)**: `resources/js/hooks/use-appearance.tsx` already implements the
starter-kit approach — `light | dark | system`, default `system`, applied via the `dark` class +
`color-scheme`, persisted to `localStorage` **and** a cookie (for SSR), with a live `matchMedia`
listener so `system` follows the OS. This is exactly the "browser default, switch to light/dark"
behaviour the spec asks for, so it is reused unchanged.

**Current state**:
- `resources/js/components/app-logo.tsx` — renders the `AppLogoIcon` placeholder + the title text
  "Laravel Starter Kit".
- `resources/js/components/app-sidebar.tsx` — composes `AppLogo`, `TeamSwitcher`, `NavMain` (Dashboard),
  `NavFooter` (Repository/Documentation links), `NavUser` inside the `Sidebar` skeleton.
- `resources/js/layouts/app/app-sidebar-layout.tsx` is the active layout → `AppSidebar` is what renders.
- `resources/js/components/appearance-tabs.tsx` exists (light/dark/system tabs) but styles itself with
  **raw `neutral-*` colors** — not reused directly (see D3).

**Project Type**: web SPA (front-end only for this feature).

**Performance/Scale**: negligible — one component swap; theme toggling is a class change on `<html>`.

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — new `ThemeSelector` + the rebranded `AppSidebar` covered by vitest
  (renders Optistock, no starter-kit nav, three theme options, default active, switching calls the hook). PASS.
- **II/III. Readable & Simple** — reuse the existing `useAppearance` hook; one small new component; delete
  unused wiring from the sidebar. No new mechanism. PASS.
- **IV. Robustness** — theming has no failure path; the hook already guards SSR/`window` absence and
  falls back to `system`. PASS.
- **VI. Consistent, Reusable Styling** — the new selector MUST use role-named color variables and shared
  primitives (not the raw `neutral-*` of `appearance-tabs.tsx`); the brand row keeps the existing sidebar
  role classes. PASS (enforced by D3).

No violations.

## Decisions

- **D1 — Rebrand only the title; keep the logo placeholder.** `app-logo.tsx`: "Laravel Starter Kit" →
  "Optistock". `AppLogoIcon` stays as the placeholder (replacing the art is out of scope per spec).

- **D2 — Strip the useless starter-kit content, keep the skeleton AND the auth user menu.** `app-sidebar.tsx`
  drops `TeamSwitcher`, `NavMain`, and `NavFooter`, keeping the `Sidebar` / `SidebarHeader` /
  `SidebarContent` / `SidebarFooter` structure and the brand row. **`NavUser` is kept** — it is the
  functional account/auth menu (profile, logout); removing it would only force rebuilding it later (user
  decision). The theme selector is added as the sidebar's own content.

- **D3 — New `ThemeSelector` reusing `useAppearance`, as a cycling toggle styled with role vars.** Rather
  than reuse `appearance-tabs.tsx` (raw `neutral-*`, violates constitution VI), add a small
  `resources/js/components/theme-selector.tsx` that calls `useAppearance`. It is a **single toggle that
  cycles** light → dark → browser → light, showing the **current mode's icon** (Sun / Moon / Monitor) plus
  the corresponding text label (**Light / Dark / Browser**) when the sidebar is expanded. When the sidebar
  is collapsed (icon mode) it reduces to the icon only (label hidden via the sidebar's
  `group-data-[collapsible=icon]:hidden` convention). "Browser" maps to the hook's existing `system` value;
  the icon+label always reflect the active mode (FR-009/FR-010). Shared primitives + role-named classes only.

- **D4 — Default unchanged.** `useAppearance` already defaults to `system` → "browser default" (FR-005)
  needs no code change; the selector simply reflects it.

## Project Structure (feature-specific)

Front-end — **change**:
- `resources/js/components/app-logo.tsx` — title text → "Optistock".
- `resources/js/components/app-sidebar.tsx` — remove `TeamSwitcher`/`NavMain`/`NavFooter` (and now-unused imports/`NavItem` arrays); **keep `NavUser`**; render `<ThemeSelector />` in the kept skeleton (e.g. footer, above `NavUser`).
- `resources/js/components/theme-selector.tsx` — **new**; `useAppearance`-backed cycling toggle (Sun/Moon/Monitor icon per mode + Light/Dark/Browser label when expanded, icon-only when collapsed); role-named colors.

Front-end — **reuse unchanged**:
- `resources/js/hooks/use-appearance.tsx` — the standard theming hook (no change).

Tests:
- `resources/js/components/theme-selector.test.tsx` — **new**: renders the three options; the active one reflects the current appearance; clicking an option calls `updateAppearance` with the right value (`light`/`dark`/`system`); default (no stored choice) shows Browser active.
- `resources/js/components/app-sidebar.test.tsx` — **new**: shows "Optistock"; shows the theme selector; **keeps** the user menu (`NavUser`); does NOT render the removed starter-kit entries (Dashboard / Repository / Documentation / team switcher). Mocks `@inertiajs/react` `usePage` + `useAppearance`.

Out of scope:
- `resources/js/components/app-header.tsx` (header layout) — the active layout is the sidebar; not touched unless that layout is later used.
- `resources/js/pages/settings/appearance.tsx` — the existing settings appearance page stays as-is.

## Flow

1. App renders the sidebar layout → `AppSidebar`.
2. The brand row shows the logo placeholder + "Optistock"; the rest of the sidebar holds the
   `ThemeSelector`.
3. `ThemeSelector` reads the current appearance from `useAppearance` and shows that mode's icon (+ label
   when expanded); Browser by default.
4. Clicking it cycles to the next mode and calls `updateAppearance(light|dark|system)` → the hook toggles
   the `dark` class, sets `color-scheme`, and persists to localStorage + cookie; the change is immediate.
5. With Browser selected, the hook's `matchMedia` listener keeps the app in sync with OS changes.

## UI contract

- **Brand row**: logo placeholder + the text "Optistock" (no other text/links beside it).
- **Theme toggle**: one control cycling Light → Dark → Browser; shows the active mode's icon (+ label when
  expanded, icon-only when collapsed); cycling applies immediately and persists; Browser follows the OS.
- **User menu**: the account/auth `NavUser` (profile, logout) is kept.
- **Responsiveness**: the sidebar keeps its existing collapse/mobile behaviour (the skeleton is unchanged).

## Design Artifacts (this run)

- `research.md` — confirmation that `useAppearance` is the standard method + the role-color styling decision.
- `data-model.md` — the Theme Preference value set + persistence.
- `contracts/header-theme-menu.md` — the sidebar/menu UI contract.
- `quickstart.md` — manual verification.

---

Generated by speckit.plan on 2026-06-09
