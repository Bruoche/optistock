# Research: Header Brand & Theme Menu

## R1 — The standard Laravel theming method

**Decision**: Reuse the starter-kit `useAppearance` hook (`resources/js/hooks/use-appearance.tsx`)
unchanged as the theming engine.

**Rationale**: It is the starter kit's standard approach and already does everything the spec needs:
values `light | dark | system`, default `system`, applied by toggling the `dark` class + `color-scheme`
on `<html>`, persisted to `localStorage` **and** a cookie (SSR), with a `matchMedia` listener so
`system` tracks the OS live. "Browser default, switch to light/dark" is precisely this. No new mechanism,
no backend.

**Alternatives considered**: A new context/store — rejected (duplicates the working hook). A backend
per-account preference — rejected (out of scope; the standard method is device-local).

## R2 — Selector component: reuse vs. new

**Decision**: Build a small new `theme-selector.tsx` that consumes `useAppearance`, instead of reusing
`appearance-tabs.tsx`.

**Rationale**: `appearance-tabs.tsx` hard-codes raw `neutral-*` colors, which violates constitution VI
(role-named colors only). A thin new selector lets us use shared primitives + role classes while still
relying on the standard hook for behaviour. Labels read **Light / Dark / Browser** (the user's wording);
the "Browser" option maps to the hook's existing `system` value.

**Alternatives considered**: Reuse `appearance-tabs.tsx` as-is — rejected on constitution VI (raw colors).
Relabel/restyle `appearance-tabs.tsx` in place — rejected: it is shared starter-kit UI also used by the
settings page; a focused sidebar selector keeps that page untouched.

## R3 — Removing the starter-kit sidebar content

**Decision**: Remove `TeamSwitcher`, `NavMain`, and `NavFooter` from `app-sidebar.tsx`, keeping the
`Sidebar` skeleton + brand row **and the `NavUser` account/auth menu**.

**Rationale**: The spec wants the menu stripped of the useless starter-kit chrome (nav, external links,
team switcher) while preserving the structure for future options. The account/auth user menu (`NavUser`
→ profile, logout) is **kept** because it is functional and removing it would only force rebuilding it
later (user decision). These are presentational changes; the Fortify auth backend is untouched (no
contract rename), so the starter-kit safety rule is respected.
