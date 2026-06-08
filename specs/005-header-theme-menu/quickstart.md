# Quickstart: Header Brand & Theme Menu

## Run

```bash
php artisan serve
npm run dev
```

## Manual verification

1. Open any page. **Confirm** the sidebar shows the logo placeholder + **"Optistock"** (not "Laravel
   Starter Kit"); the account/user menu is still present; and no team switcher / Dashboard / Repository /
   Documentation entries remain.
2. Confirm the sidebar shows the **theme selector** (Light / Dark / Browser) with the active option
   marked.
3. With no prior choice (clear `localStorage`), confirm **Browser** is active and the app matches the OS
   theme.
4. Pick **Light**, then **Dark** — the app switches immediately, no reload.
5. Reload — the last choice is still in effect.
6. Pick **Browser**, change the OS light/dark setting — the app follows live.
7. Collapse the sidebar / use a mobile width — confirm it still behaves as before.

## Tests

```bash
npm run test -- theme-selector app-sidebar
```
