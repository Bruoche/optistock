# Contract: Header Brand & Theme Menu

## Sidebar brand row

- Shows the logo placeholder (`AppLogoIcon`) + the title text **"Optistock"**.
- No other text/links beside the title (the starter-kit team switcher / nav / footer links are removed).
- The account/auth user menu (`NavUser`) is **kept**.
- The `Sidebar` skeleton and its collapse/mobile responsiveness are unchanged.

## Theme toggle

- One control that **cycles** through three states: **Light → Dark → Browser → Light**.
- Maps to `useAppearance` values: Light → `light`, Dark → `dark`, Browser → `system`.
- Shows the active mode's icon (Sun / Moon / Monitor); when the sidebar is expanded it also shows the
  matching label (Light / Dark / Browser); when collapsed it shows the icon only.
- Clicking calls `updateAppearance(nextValue)` → applies immediately (no reload) and persists
  (localStorage + cookie).
- Default with no stored choice: **Browser** active (the hook's `system` default).
- While Browser is active, the app follows the OS appearance and updates live on OS change.

## Styling

- Role-named color variables + shared primitives only; no raw `neutral-*`/hex (constitution VI).

## Out of scope

- The Laravel logo art (kept as placeholder).
- The header layout (`app-header.tsx`) and the settings appearance page.
