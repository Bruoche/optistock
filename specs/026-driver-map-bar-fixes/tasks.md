---
description: "Task list for Driver Page Map & Day-Bar Fixes"
---

# Tasks: Driver Page Map & Day-Bar Fixes

**Input**: Design documents from `specs/026-driver-map-bar-fixes/`

**Prerequisites**: plan.md, spec.md, research.md, contracts/ui-map-bar.md

**Tests**: One test edit only (the deliberately-changed opacity value); no new tests — the map's on-load rendering and removed duplicate line are structural MapLibre behaviours jsdom cannot render (verified by the manual walkthrough + the paint-prop assertion). Backend is frozen and its tests must stay green.

**Scope guard**: Frontend only. Exactly 3 components + 1 test change. Nothing outside plan.md's "Files touched" may be modified.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: different file, no dependency on an incomplete task
- Same-file tasks are sequential (US1/US2 both edit `day-layer.tsx` + `manage.tsx`; US3 edits `manage.tsx` too)

---

## Phase 1: Setup

No setup — existing project, existing files, no new dependency.

---

## Phase 2: Foundational

None — the three fixes are independent edits to already-shipped files. Proceed straight to the user stories.

---

## Phase 3: User Story 1 - See the day's tours the moment the page opens (Priority: P1) 🎯 MVP

**Goal**: Tour + connection lines draw on load (straight fallback → polyline), with no click required.

**Independent Test**: Open a driver-day with assigned tours, take no action → lines are visible immediately and become road-accurate as tracing lands; an untraceable segment keeps its straight line; empty day shows only the warehouse marker.

### Implementation for User Story 1

- [x] T001 [US1] In `resources/js/components/driver/day-layer.tsx`, remove `beforeId={TOUR_ROUTE_LAYER_ID}` from the `Layer`, and remove the now-unused `TOUR_ROUTE_LAYER_ID` import (from `@/components/tour/route-layer`). Legs must render from `geometry ?? path` with no dependency on a conditionally-mounted layer (FR-001, FR-004). Z-order safeguard: draw non-highlighted legs before highlighted ones so the selected tour + its bracketing connections stay on top (previously guaranteed by `beforeId`); each leg keeps its stable `day-{kind}-{index}` id so selection only paint-diffs, never remounts.
- [x] T002 [US1] In `resources/js/pages/driver/manage.tsx`, remove the selected-tour `<RouteLayer …>` block and its `import { RouteLayer } from '@/components/tour/route-layer'` (the straight stop-path line that duplicated `DayLayer`'s highlighted leg). Keep `TourMap stops={selectedStops}` (the numbered stop markers) and the `DayLayer`/`DayMarkers` children unchanged otherwise (FR-005, FR-006).

**Checkpoint**: Reload the page — tour + connection lines appear immediately, straight then polyline, without clicking.

---

## Phase 4: User Story 2 - One clean line per segment, correctly emphasised (Priority: P2)

**Goal**: Exactly one line per segment (no straight-over-polyline), neutral lines at ~75% opacity when nothing is selected; selection keeps 1.0 / 0.5.

**Independent Test**: Multi-tour day loaded — each segment shows one line; select a tour → it + bracketing drives full-strength, rest ~50%, no leftover straight line; deselect → all-neutral ~75%.

> Note: the "single line per segment" half of this story is already delivered by US1's `RouteLayer` removal (T002). US2 adds the opacity change and its test.

### Implementation for User Story 2

- [x] T003 [US2] In `resources/js/components/driver/day-layer.tsx`, change `line-opacity` to `isHighlighted ? 1 : (hasSelection ? 0.5 : 0.75)` — no-selection neutral = 0.75, selected = 1.0, other-while-selected = 0.5. Leave `line-color`, `line-width`, and `line-dasharray` unchanged (FR-007, FR-008, FR-009, FR-010). (Same file as T001 — do after T001.)

### Test for User Story 2

- [x] T004 [US2] In `resources/js/components/driver/day-layer.test.tsx`: (a) update the "nothing selected" case to assert every layer's `data-opacity` is `'0.75'` (was `'1'`); (b) the highlight case must NO LONGER assume DOM index === input-leg index (T001 reorders highlighted legs last for z-order) — assert instead that exactly the selected tour + its two bracketing connections are `PRIMARY`/`'1'` and the rest `NEUTRAL`/`'0.5'` (by count/membership), and that the highlighted layers are rendered after the neutral ones (trailing in DOM order).

**Checkpoint**: One line per segment throughout; neutral at 75%, selection emphasis intact; `day-layer.test.tsx` green.

---

## Phase 5: User Story 3 - Day bar under the map with aligned labels (Priority: P3)

**Goal**: Bar sits below the map and above the tour list; weekday is a title label above the date field only, aligned with the other bar labels; arrows on the value row.

**Independent Test**: Load the page — bar is between map and list; weekday label lines up with the other labels; prev/next arrows sit beside the date on the value row; no horizontal overflow when narrow.

### Implementation for User Story 3

- [x] T005 [US3] In `resources/js/pages/driver/manage.tsx`, move the `<DayBar …/>` element from above the map region to between the map region and the tour-list region (page order: identity → map → day bar → tour list). Do not change its props (FR-011). (Same file as T002 — do after T002.)
- [x] T006 [P] [US3] In `resources/js/components/driver/day-bar.tsx`, restructure the day-navigation group so the weekday name is a title label above the date field only, with `[prev][date][next]` on the value row (`items-end` so arrows bottom-align to the date input; weekday not above the arrows), and align all bar labels on one row / values beneath using the existing `Figure` label/value shape. Keep `flex-wrap` for mobile; change no other control or behaviour (FR-012, FR-013, FR-014, FR-015).

**Checkpoint**: Bar below the map with the weekday label aligned; responsive intact.

---

## Phase 6: Polish & Verification

- [x] T007 Palette + scope check: confirm no off-palette colour introduced (opacity only; colours stay `--route-neutral`/`--primary`; bar keeps role tokens), and that only `day-layer.tsx`, `manage.tsx`, `day-bar.tsx`, `day-layer.test.tsx` changed (FR-016, FR-017).
- [x] T008 Run the full gate: `npm run lint:check`, `npm run format:check`, `npm run types:check`, `npm run test` (Vitest — `day-layer.test.tsx` asserts 0.75), and `composer test` (PHPUnit unchanged, must stay green). All green.
- [ ] T009 Manual walkthrough per `quickstart.md` (`npm run dev`): tours draw on load (straight→polyline); one line per segment on select/deselect; neutral 75% vs selected 1.0/0.5; bar below map with weekday label aligned; no horizontal overflow 320–2560 px.

---

## Dependencies & Execution Order

- **US1 (T001, T002)** — the MVP; fixes both on-load rendering and (via T002) the single-line defect.
- **US2 (T003, T004)** — T003 is the same file as T001, so it follows T001; T004 [P] can be written alongside.
- **US3 (T005, T006)** — T005 is the same file as T002, so it follows T002; T006 [P] is an independent file.
- **Polish (T007–T009)** — after all stories.

### Parallel opportunities

- Small feature: the only true parallelism is T004 (test) and T006 (`day-bar.tsx`), which touch files no other pending task edits. `day-layer.tsx` (T001→T003) and `manage.tsx` (T002→T005) are each sequential within themselves.

### Suggested order (single developer)

T001 → T003 (both `day-layer.tsx`) → T002 → T005 (both `manage.tsx`) → T006 (`day-bar.tsx`) → T004 (test) → T007 → T008 → T009.

---

## Implementation Strategy

### MVP (US1)

Do T001 + T002 → reload → tours draw on load and each shows a single line. This alone resolves the two most visible defects.

### Incremental

US1 (on-load + single line) → US2 (75% opacity + test) → US3 (bar placement + label). Each is independently verifiable.

---

## Notes

- Endpoints/payloads and all backend are frozen — `composer test` must remain green untouched.
- jsdom renders no MapLibre paint; on-load drawing and the removed duplicate line are confirmed by the manual walkthrough, opacity by the mocked-`Layer` paint assertion.
- Do not modify `RouteLayer`, `TourMap`, `DayMarkers`, `TourList`, `TourRow`, or the hooks.
