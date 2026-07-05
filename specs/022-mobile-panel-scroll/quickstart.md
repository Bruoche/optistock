# Quickstart: Mobile Scrollable Content Panel

## What this feature does
On phones, the bottom panel (orange bar + list) becomes one scroll surface: you scroll the bar up under the map to reach the whole driver / stop list, and the bar sits edge-to-edge with no framing border. Desktop is unchanged.

## Try it
1. Open the tour screen on a desktop width. Confirm the orange bar is inset with padding + rounded corners, and the list scrolls within its own area — exactly as before.
2. Switch to a phone width (~360px, dev-tools device mode).
3. Confirm the orange bar now sits edge-to-edge (touches both side edges, no dark border framing it) with square corners.
4. In the result view, scroll the panel up: the orange bar moves up and disappears beneath the map (it must never draw over the map), and the driver list comes fully into reach — scroll to and tap the last driver.
5. Repeat in the editing view (bar + stop list) — the stop list is reachable the same way.
6. Return to desktop width and confirm nothing changed.

## Key files
- `resources/js/pages/tour/optimize.tsx` — panel `max-md:overflow-y-auto max-md:p-0`
- `resources/js/components/tour/result-summary.tsx`, `stop-list.tsx` — wrapper `max-md:h-auto`, list `max-md:flex-none`
- `resources/js/components/tour/driver-list.tsx` — list `max-md:flex-none`
- `resources/js/components/tour/tour-control-bar.tsx`, `result-summary.tsx` — bar `max-md:rounded-none`

## Verify (CI gate — run all before done)
- `npm run test` — the `max-md:` override classes are present on the panel, wrappers, lists, and bars (jsdom can't evaluate the media query or measure scroll).
- `npm run lint`, `npm run types`, `npm run format:check` — all green.
- `npm run build` — confirms the classes compile.
- Manual: the phone-width walkthrough above, plus a desktop-unchanged check.

## Watch out for
- **Desktop must be untouched**: every change is a `max-md:` variant; if a desktop class changed, that's a mistake.
- **Bar over the map**: at no scroll position may the bar render above the map's bottom edge — it clips at the panel's top.
- **Single scroll, not two**: on mobile the list should not scroll independently inside a tiny box — the whole panel scrolls as one.
