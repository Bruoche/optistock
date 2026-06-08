# Contract: Header Brand & Theme Menu

## Sidebar brand row

- Shows the logo placeholder (`AppLogoIcon`) + the title text **"Optistock"**.
- No other text, links, or controls beside the title (the starter-kit team switcher / nav / footer
  links / user menu are removed).
- The `Sidebar` skeleton and its collapse/mobile responsiveness are unchanged.

## Theme selector

- Three mutually-exclusive options: **Light**, **Dark**, **Browser**.
- Maps to `useAppearance` values: Light → `light`, Dark → `dark`, Browser → `system`.
- The currently-active option is visibly indicated.
- Selecting an option calls `updateAppearance(value)` → applies immediately (no reload) and persists
  (localStorage + cookie).
- Default with no stored choice: **Browser** active (the hook's `system` default).
- While Browser is active, the app follows the OS appearance and updates live on OS change.

## Styling

- Role-named color variables + shared primitives only; no raw `neutral-*`/hex (constitution VI).

## Out of scope

- The Laravel logo art (kept as placeholder).
- Relocating auth/profile actions removed with `NavUser`.
- The header layout (`app-header.tsx`) and the settings appearance page.
