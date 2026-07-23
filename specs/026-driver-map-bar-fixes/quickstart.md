# Quickstart: Driver Page Map & Day-Bar Fixes

## Run
```
npm run dev   # + php artisan serve / usual runner
```
Open `/driver/{id}` for a driver with assigned tours on the selected date.

## Verify (maps to the spec)
1. **On load (US1)** — without clicking anything, tour + connection lines appear immediately (straight first), then become road-accurate as tracing lands. An untraceable segment keeps its straight line. Empty day → warehouse marker only.
2. **Single line + opacity (US2)** — every segment shows one line, never a straight line over a polyline. With nothing selected the neutral lines are lightly dimmed (~75%). Select a tour → it + its bracketing drives go full-strength primary, the rest dim to ~50%, and no leftover straight line appears over the selected polyline. Deselect → back to the all-neutral 75% state, single line each.
3. **Day bar (US3)** — the bar sits below the map and above the tour list. The weekday name is a label above the date field, aligned with the other bar labels; the prev/next arrows sit beside the date on the value row (weekday not above the arrows). Resize narrow → groups wrap, no horizontal overflow.

## Automated checks (full gate)
```
npm run lint:check
npm run format:check
npm run types:check
npm run test          # Vitest — day-layer.test.tsx now asserts 0.75 for the no-selection case
composer test         # PHPUnit — unchanged, must stay green (frozen backend)
```

## Key points
- Only `day-layer.tsx`, `manage.tsx`, `day-bar.tsx`, and `day-layer.test.tsx` change. Everything else is untouched.
- MapLibre paint isn't evaluated in jsdom — the on-load rendering and the removed duplicate line are confirmed by the manual walkthrough; opacity is asserted via the mocked `Layer` paint props.
