# Quickstart: Driver Management Page

## Prerequisites
- App running per the repo's standard dev setup (Laravel + Vite). A seeded driver with a warehouse, ≥1 delivery mode, and ≥2 tours assigned on a date via the existing assign flow.
- New front-end dependency: `npm i @dnd-kit/core @dnd-kit/sortable @dnd-kit/modifiers`.

## Run
```
composer install && npm install
php artisan migrate         # no new migration in this feature
npm run dev                 # + php artisan serve / your usual runner
```

## Manual walkthrough (maps to user stories)
1. **View a day (US1)** — open `/driver/{id}`. Identity block (picture/name/modes/warehouse), day bar with Total workday / Driven / Stop / Break, map with the day's solid neutral tours + dotted connections + warehouse marker + `T1…Tn`, and the ordered tour list with per-tour durations. Empty day → warehouse-only map + "no tours assigned".
2. **Flip days (US1)** — prev/next arrows and the date field re-fetch without a full reload; rapid clicks settle on the last date.
3. **Select a tour (US2)** — click a row: its path + bracketing dotted drives turn primary, others dim, numbered stop markers appear, the row unfolds to `index / coordinate / duration` stops matching the markers. Re-click clears.
4. **Edit driver (US3)** — change name/modes/warehouse; Update enables only when something differs. Changing the warehouse prompts the advisory. Save → confirmation, Update disables. Empty name / no mode → 422 with the field named.
5. **Reorder (US4)** — drag a row by its left handle; Tour-order Update enables and `T` markers relabel. Save → recomputed day returns. With routing down, the normal save is blocked and a **Force save** appears; force persists the order degraded.
6. **Edit a tour (US5)** — a row's Edit opens the tour-edit screen; a successful re-optimize returns to this driver + date with updated figures; back/cancel returns unchanged; a failed optimize stays on the edit screen.

## Automated checks (full CI gate — run all before "done")
```
composer ci:check        # canonical full gate: npm lint:check + format:check + types:check + @test
```
Or run the pieces (exact repo script names):
```
composer test            # pint lint:check + php artisan test  (PHPUnit feature + unit)
npm run lint:check       # ESLint (NOT `npm run lint` — that is eslint --fix and mutates files)
npm run types:check      # tsc --noEmit  (NOT `npm run types`)
npm run format:check     # Prettier — SEPARATE from ESLint; do not skip
npm run test             # Vitest
```

## Key verification points
- Every backend-sourced value shows a placeholder/spinner while loading and "unavailable" (never a fabricated 0) when it can't be retrieved (FR-036/037/038).
- Stale day/selection responses are discarded (FR-039); no stuck spinner or duplicate map layer after rapid interaction (FR-040).
- Reorder never persists a partially-recomputed order (422 blocks; force is explicit).
- Frozen endpoints/props unchanged — existing optimize/geometry/drivers/assign tests pass untouched (see contracts/frozen-io.md).
- No horizontal overflow 320–2560 px; tour selection + reorder operable by touch (FR-042).
- No off-palette colours (Constitution VI).
