# Quickstart: Mobile Responsive Interface

## What this feature does
When the tour screen's top bar is wider than the viewport, the bar scrolls sideways within its own rounded box instead of controls spilling out and getting cut off. When it fits, nothing changes.

## Try it
1. Open the tour optimization screen. In desktop width, confirm the editing bar (mode · loop · date · Optimize) and — after optimizing — the result bar (figures · New · Edit · Assign) look exactly as before.
2. Shrink the window (or open dev-tools device mode) to a phone width (~360px, then ~320px).
3. Confirm the top bar no longer spills its controls past the rounded box; instead it becomes a horizontal scroll strip.
4. Swipe/drag the bar sideways and confirm every control is reachable and operable in both the editing and the result views.
5. Confirm the page as a whole does not scroll horizontally — only the bar's strip does.

## Key files
- `resources/css/app.css` — `@utility scroll-x-contained` (single source)
- `resources/js/components/tour/tour-control-bar.tsx` — editing bar
- `resources/js/components/tour/result-summary.tsx` — result bar

## Verify (CI gate — run all before done)
- `npm run test` — bar roots carry `scroll-x-contained` (jsdom cannot measure real overflow).
- `npm run lint`, `npm run types`, `npm run format:check` — all green.
- Manual: the narrow-viewport walkthrough above (scroll behavior is visual, not unit-testable).

## Watch out for
- The non-overflowing (desktop) look must be unchanged — `overflow-x: auto` and `shrink-0` must not alter the fits state.
- The bars' menus (mode dropdown, native date picker) must still open fully — they portal / are native, so overflow does not clip them; confirm during the walkthrough.
