# Data Model: Header Brand & Theme Menu

No backend entities. One client-side preference, already implemented by `useAppearance`.

## Theme Preference

- Values: `light` · `dark` · `system` (default `system`).
- UI labels: **Light** · **Dark** · **Browser** — "Browser" is the visible label for the `system` value.
- Default: `system` (browser/OS) when the user has made no choice.
- Persistence: `localStorage['appearance']` + an `appearance` cookie (for SSR), set by the hook. No
  account/server storage.
- Applied effect: toggles the `dark` class and `color-scheme` on `<html>`; while `system`, a `matchMedia`
  listener re-applies on OS theme change.

No new types are introduced; the front-end `Appearance` type (`light | dark | system`) from
`use-appearance.tsx` is reused.
