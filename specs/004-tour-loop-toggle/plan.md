# Implementation Plan: Tour Loop Toggle

**Branch**: `004-tour-loop-toggle` | **Date**: 2026-06-08 | **Spec**: [spec.md](spec.md)

## Summary

Expose the tour shape — **closed loop** (current, default) vs **open one-way** — as a user toggle beside
the 003 delivery-mode dropdown. The choice drives **both** the optimization (TSP `tour` field, value
`closed`|`open`) **and** the drawn route (omit the closing leg when open). Today `OpenStreetTspClient`
hard-codes `tour=closed` and `TourGeometryService` always traces the closing leg (`(i+1) % count`). This
feature threads a boolean `loop` (true = closed) through the same seams 003 used for `mode`:
request → service → **cache key** → job → TSP client, and request → geometry service → route client;
the front snapshots `loop` with the result so the route's shape always matches the optimization (FR-007).

## Technical Context

**Stack (reuse 001/002/003)**: Laravel 13 / PHP 8.3; React 19 + Inertia + Tailwind v4 + shadcn/ui.
Session-cookie `/api`; async optimization job + Reverb (001); synchronous `/route` trace (002); mode
selector + mode-keyed cache + control bar (003).

**API contract (resolved by the user — no live re-probe needed)**: the OpenStreet TSP endpoint takes a
`tour` field with value **`closed`** or **`open`**. The boolean `loop` is mapped to that string in the
**job** (see D1); the client just forwards it.

**Current state (what exists vs. what's missing)**:
- `OpenStreetTspClient::send()` hard-codes `'tour' => 'closed'` — must accept a forwarded `tour` string (the job supplies `closed`/`open`).
- `OptimizeTourRequest` / `OptimizeTourJob` / `TourOptimizationService` / `TourCache` carry no loop.
- `TourGeometryService::trace()` always appends the closing leg (`$stops[($index + 1) % $count]`).
- `TourGeometryRequest` validates `stops` + `mode` only.
- Front: `RouteLayer` already has a `closed` prop (defaults true) that gates the return segment, and
  `useTourGeometry`'s `composeGeometry` already returns a `closed` flag — both just need to follow `loop`.

**Default**: `loop` defaults to **true** (closed) when a request omits it — preserves today's behaviour
and 001/002/003 callers/tests that send no loop.

**Project Type**: web application (Laravel API + React/Inertia SPA).

**Performance/Scale**: unchanged. Adding `loop` to the cache key at most doubles entries per (mode,
coordinates) — negligible.

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — new/changed behavior covered: optimize request `loop` validation + the
  full thread to job/client, cache **loop separation** (closed vs open ⇒ distinct entry), `tour=open|closed`
  in the TSP query, geometry omits the closing leg when open, route metrics exclude the return leg when
  open, front sends/snapshots `loop`, `LoopToggle` renders/defaults on. Existing tests updated for the
  new arity (the 003 lesson — see Decisions D5). PASS.
- **II/III. Readable & Simple** — one boolean threaded along the existing seams; mapped to `closed`/`open`
  in exactly one place (the client). One small new component (`LoopToggle`) added to the existing control
  bar. No new async machinery. PASS.
- **IV. Robustness / no silent failure** — `loop` validated as boolean (422 otherwise); omitted → safe
  default (closed); 002 per-leg/whole-tour fallback + logging unchanged. Route↔optimization shape
  congruence (FR-007) guaranteed by snapshotting, not live UI state. PASS.
- **V. Performance with Clarity** — no extra round-trips; `loop` rides existing requests; open tours make
  **one fewer** upstream `/route` call (no closing leg). PASS.
- **VI. Consistent, Reusable Styling** — toggle reuses the shared `Toggle` primitive + role-named colors;
  no raw hex, no duplicated rules. PASS.

No violations. (Re-evaluated post-design: still none.)

## Decisions

- **D1 — `loop` is an explicit boolean threaded request → job → cache → TSP client (mirrors 003 `mode`);
  the bool→`open`/`closed` translation lives in the job.** Add `loop` (boolean) to `OptimizeTourRequest`;
  thread through `TourOptimizationService::optimize($userId, $coordinates, $mode, $loop)` →
  `OptimizeTourJob` (new readonly `bool $loop`). The **job** translates `$loop ? 'closed' : 'open'` and
  passes that string to `OpenStreetTspClient::optimize($coordinates, $mode, $tour)`, which simply
  forwards it into the `tour` query field (thin client, no bool logic). Keeping a boolean at the HTTP/UI
  boundary is the natural fit for a checkbox/toggle; the domain→API string mapping sits in the job, the
  same layer where other API-shape concerns live. Omitted `loop` → defaults to `true` (closed).

- **D2 — The tour cache key includes the loop shape.** A closed tour differs from an open one, so the
  cache must not serve one for the other. Extend the 003 keys with a shape segment:
  `tour:{mode}:{shape}:{hash}` and `tour:active:{userId}:{mode}:{shape}:{hash}`, where `{shape}` is
  `closed`|`open`. `coordinatesHash` keeps meaning coordinates only; mode (003) and shape (004) are
  explicit, readable cache dimensions. *Alternative rejected*: folding shape into the hash (hides the
  dimension; muddies `coordinatesHash`).

- **D3 — Geometry omits the closing leg when open.** `TourGeometryService::trace($stops, $mode, $loop)`
  iterates the closing leg (`(i+1) % count`) only when `$loop`; when open it traces legs `0..count-2`
  and stops at the last stop. `composeGeometry` (front) sets the `RouteLayer` `closed` flag from `loop`
  (straight fallback) and never appends a return for an open tour. Totals naturally exclude the return
  leg (FR-009).

- **D4 — Loop snapshotted with the result; the live toggle does not retro-edit a shown tour
  (FR-007/FR-008).** `optimize(mode, loop)` records `loop` into the optimization state; the `done` state
  carries it; `useTourGeometry(result, mode, loop)` sends the snapshot. Mirrors 003 mode handling.

- **D5 — Update existing tests in lockstep with the signature changes (the 003 regression lesson).**
  Adding `loop` to `OptimizeTourJob`, `TourCache`, the two services and clients breaks existing
  arity-bound tests (`TourCacheTest`, `TourOptimizationBroadcastTest`, `TourOptimizationTest`,
  `TourGeometryTest`, and the front hook tests). Each breaking change's task MUST update those call
  sites so the suite stays green (constitution I).

- **D6 — Editing-only toggle beside the mode dropdown.** The `LoopToggle` is added to `TourControlBar`
  (003) to the right of `ModeSelect`; both live only in the editing view. Default on; disabled while
  optimizing; retained across reset (page state); not persisted across sessions. Matches spec FR-001/003
  and the 003 control pattern.

## Project Structure (feature-specific)

Backend — **change**:
- `app/Http/Requests/OptimizeTourRequest.php` — add `loop` rule (`sometimes`, `boolean`).
- `app/Http/Requests/TourGeometryRequest.php` — add `loop` rule (`sometimes`, `boolean`).
- `app/Http/Controllers/TourOptimizationController.php` — read `validated('loop') ?? true`, pass to service.
- `app/Http/Controllers/TourGeometryController.php` — read `validated('loop') ?? true`, pass to `trace()`.
- `app/Services/TourOptimizationService.php` — `optimize(int $userId, array $coordinates, string $mode, bool $loop)`; pass `loop` to every `TourCache` call and the dispatched job.
- `app/Services/TourCache.php` — add a `bool $loop` parameter to `tourKey`, `activeJobKey`, `getTour`, `putTour`, `claimActiveJob`, `getActiveJob`, `releaseActiveJob`; the key builder maps it to a readable `closed`/`open` shape segment internally (callers pass the bool).
- `app/Jobs/OptimizeTourJob.php` — new readonly `bool $loop`; **translate** `$tour = $loop ? 'closed' : 'open'` and pass it to `OpenStreetTspClient::optimize($coordinates, $mode, $tour)`; pass the boolean `$loop` to the shape-keyed `TourCache` calls (handle + failed); include in log context.
- `app/Services/OpenStreetTspClient.php` — `optimize(array $coordinates, ?string $mode = null, ?string $tour = null)`; query `'tour' => $tour ?? 'closed'` (thin forwarder; default `closed` preserves today's behaviour — no bool logic in the client).
- `app/Services/TourGeometryService.php` — `trace(array $orderedStops, ?string $mode, bool $loop = true)`; build the closing leg only when `$loop`.
- `app/Providers/AppServiceProvider.php` — **no change**: the client's new `?string $tour = null` is a defaulted method arg, so the existing named-arg binding stays valid.

Front-end — **change**:
- `resources/js/types/tour.ts` — add `loop: boolean` to the `submitting`/`pending`/`done` `OptimizeState` variants.
- `resources/js/components/tour/loop-toggle.tsx` — **new** toggle (shadcn `Toggle`, pressed state); props `{ value, onChange, disabled }`; default-on handled by page state; clear on/off label.
- `resources/js/components/tour/tour-control-bar.tsx` — add `LoopToggle` to the right of `ModeSelect`; new props `loop`, `onLoopChange`.
- `resources/js/hooks/use-tour-optimization.ts` — `optimize(mode, loop)`; thread `loop` through submit body + states; snapshot into `done`.
- `resources/js/hooks/use-tour-geometry.ts` — accept `loop`; send `{ stops, mode, loop }`; set `closed` from `loop` in `composeGeometry`.
- `resources/js/pages/tour/optimize.tsx` — own `loop` state (default `true`), pass to the control bar + `optimize`, and pass the snapshotted `state.loop` to `useTourGeometry`.

Tests:
- `tests/Unit/TourCacheTest.php` — extend: closed vs open ⇒ distinct keys/entries (no cross-shape hit); update existing arity assertions.
- `tests/Feature/TourOptimizationTest.php` — extend: `loop` validation, omitted → closed default, `tour=open|closed` reaches the TSP query + the job; update existing `putTour`/job assertions for new arity.
- `tests/Feature/TourOptimizationBroadcastTest.php` — update `OptimizeTourJob` construction + `TourCache` calls for the new arity.
- `tests/Feature/TourGeometryTest.php` — extend: open tour omits the closing leg (one fewer upstream call / leg); update `trace()` calls for the new arg.
- `resources/js/hooks/use-tour-optimization.test.ts` — sends `loop`; `done` carries it.
- `resources/js/hooks/use-tour-geometry.test.ts` — sends `loop`; open ⇒ `closed:false`, no appended return.
- `resources/js/components/tour/loop-toggle.test.tsx` — **new**: default-on display, fires onChange, disabled.

## Flow (loop-aware)

1. Planner sets the loop toggle (default on) beside the mode dropdown and clicks **Optimize route**.
2. Front POSTs `/api/tour/optimize` with `{ coordinates, mode, loop }`.
3. `TourOptimizationService::optimize` hashes coordinates and looks up the cache **under {mode}:{shape}**;
   on miss, claims the per-(mode,shape) active-job slot and dispatches `OptimizeTourJob` carrying `loop`.
4. The job translates `loop` → `tour=closed|open` and calls
   `OpenStreetTspClient::optimize($coordinates, $mode, $tour)`; the TSP query carries that value; the tour
   is cached under the shape (keyed by the boolean) and broadcast as today.
5. Front shows the result, snapshotting `loop` (and mode) into the `done` state.
6. `useTourGeometry(result, mode, loop)` POSTs `/api/tour/geometry` with `{ stops, mode, loop }`; the
   service traces the closing leg only when looped; the route draws (or omits) the return accordingly.

## API Contract changes

- `POST /api/tour/optimize` request gains optional `loop` (boolean, default true). Responses unchanged.
- `POST /api/tour/geometry` request gains optional `loop` (boolean, default true). Response shape
  unchanged (an open tour simply returns one fewer leg and totals without the return).

## Design Artifacts (this run)

- `research.md` — `tour` field values + cache-shape & default decisions.
- `data-model.md` — `loop` boolean, shape-aware cache keys, request/state shapes.
- `contracts/tour-loop.md` — the two endpoints' `loop` field + the toggle UI contract.
- `quickstart.md` — env, run, manual verification of looped vs open across modes.

---

Generated by speckit.plan on 2026-06-08
