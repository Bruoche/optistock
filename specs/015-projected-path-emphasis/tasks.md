---
description: "Task list for Projected Path Emphasis (015)"
---

# Tasks: Projected Path Emphasis

**Input**: Design documents from `specs/015-projected-path-emphasis/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/driver-workday.md, quickstart.md

**Tests**: Included — the constitution (I. Quality First) requires tests for changed behavior; the plan enumerates them.

**Organization**: Grouped by user story. Both stories are driven by one server-set `highlight` flag: US1 maps it to color, US2 to opacity. `RouteLayer` is deliberately **not** touched (decision D3).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1 / US2 for story-phase tasks

## Path Conventions

Web app (Laravel + React SPA): backend under `app/`, `tests/`; frontend under `resources/js/`.

---

## Phase 1: Setup

**Purpose**: Confirm the 014 baseline is green before touching it.

- [X] T001 Run the baseline suites and confirm green: `php artisan test` and `npm run test` (guards against attributing a pre-existing failure to this feature).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Plumb the `highlight` flag end to end (backend field → payload → frontend type) and keep the 014 test literals compiling. Both user stories depend on the flag existing on each leg.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 [P] Add readonly `bool $highlight` to `WorkdayLeg` in `app/Services/WorkdayLeg.php`: constructor param; `connection()` factory gains `bool $highlight = false`; `tour()` constructs with `false`; `toArray()` emits `'highlight' => $this->highlight`.
- [X] T003 In `WorkdayLegsBuilder` (`app/Services/WorkdayLegsBuilder.php`) add `bool $highlight = false` to the private `connection()` helper and pass `highlight: true` on the two candidate-bracketing calls (`… → $candidateStart` and `$candidateEnd → …`); prior-tour connections keep the default `false` (depends on T002).
- [X] T004 [P] Extend `tests/Unit/WorkdayLegsBuilderTest.php`: the two candidate-bracketing connections have `highlight === true`, every prior-tour connection and every `tour` leg `false`, and the no-prior-tours case yields both legs `true`.
- [X] T005 [P] Extend `tests/Feature/DriverAvailabilityTest.php`: assert each leg in the drivers payload carries `highlight`, `true` only on the two candidate-adjacent connections and in the correct positions.
- [X] T006 [P] Add `highlight: boolean` (required) to the `WorkdayLeg` type in `resources/js/types/tour.ts` with the doc comment from data-model.md; no `use-tour-drivers` change (legs are copied verbatim).
- [X] T007 [P] Add `highlight: false` to the existing `WorkdayLeg` literals so the 014 suites still compile: the `leg()` factory default in `resources/js/components/tour/workday-layer.test.tsx`, and the `leg()` factory default **plus** the two inline connection literals (~L87, ~L155) in `resources/js/hooks/use-workday-preview.test.ts`.

**Checkpoint**: `highlight` present on every leg in the payload and type; all 014 tests compile and stay green.

---

## Phase 3: User Story 1 - Bracketing connections in the primary color (Priority: P1) 🎯 MVP

**Goal**: The two connection drives bracketing the candidate tour draw in the primary role color; all other legs stay neutral.

**Independent Test**: Preview a driver with ≥1 prior tour — the drive into the candidate start and out of its end are primary-colored; warehouse/between-prior connections stay neutral; all still dotted.

- [X] T008 [US1] In `resources/js/components/tour/workday-layer.tsx` add a local `primaryColor()` resolver of `--primary` (mirror the existing `neutralColor()`, fallback `#ff9a3c`) and set `line-color` per leg to `leg.highlight ? primaryColor() : neutralColor()`; `line-dasharray` stays keyed to `leg.dotted`. Do **not** modify `route-layer.tsx` (depends on T006).
- [X] T009 [P] [US1] Extend `resources/js/components/tour/workday-layer.test.tsx`: set `--primary` in `beforeEach`; a `highlight: true` leg paints `line-color` = the primary value, a `highlight: false` leg the neutral value; a highlighted connection still dashes `[0.5, 2]` (color independent of dash).

**Checkpoint**: US1 fully functional — the projected tour's connecting drives read as primary orange.

---

## Phase 4: User Story 2 - Recede pre-existing paths at half opacity (Priority: P2)

**Goal**: Every non-highlighted leg draws at 50% opacity; the candidate emphasis set stays fully opaque.

**Independent Test**: Preview a driver with ≥1 prior tour — prior tours and neutral connections render at `0.5` opacity while the two highlighted connections render at `1`.

- [X] T010 [US2] In `resources/js/components/tour/workday-layer.tsx` add a `line-opacity` paint property set to `leg.highlight ? 1 : 0.5` (depends on T008 — same file/paint object).
- [X] T011 [P] [US2] Extend `resources/js/components/tour/workday-layer.test.tsx`: a `highlight: true` leg has `line-opacity` `1`, a `highlight: false` leg `0.5`, and opacity is the same whether the leg has `geometry` or only `path` (independent of geometry state, FR-007).

**Checkpoint**: US1 + US2 — orange emphasis set at full opacity, everything else dimmed.

---

## Phase 5: Polish & Cross-Cutting

- [X] T012 [P] Run the full suites and linters green: `php artisan test`, `npm run test`, `npm run lint`, and Pint.
- [ ] T013 (manual — needs a visual pass in the running app) Walk through `specs/015-projected-path-emphasis/quickstart.md` in the app: prior-tour driver (two tiers), no-prior driver (all orange), progressive upgrade keeps tier, rapid cycling stable.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (T001)**: none.
- **Foundational (T002–T007)**: after Setup. Blocks both user stories.
- **US1 (T008–T009)**: after Foundational.
- **US2 (T010–T011)**: after US1 (T010 edits the same `workday-layer.tsx` paint object T008 introduces). US2 is otherwise independently testable.
- **Polish (T012–T013)**: after all stories.

### Within Foundational

- T002 → T003 (builder uses the widened factory).
- T004, T005, T006, T007 are mutually independent files (T007 touches only test files, T006 only the type).

### Parallel Opportunities

- Foundational: T002, T004, T005, T006, T007 can start together (distinct files); T003 waits on T002.
- US1 test T009 runs alongside any non-`workday-layer.tsx` work.
- Polish T012 is independent of T013.

---

## Parallel Example: Foundational

```bash
# Distinct files, launch together:
Task: "Add highlight to WorkdayLeg in app/Services/WorkdayLeg.php"                 # T002
Task: "Extend WorkdayLegsBuilderTest highlight positions"                          # T004
Task: "Extend DriverAvailabilityTest highlight in payload"                         # T005
Task: "Add highlight to WorkdayLeg type in resources/js/types/tour.ts"             # T006
Task: "Add highlight:false to existing WorkdayLeg test literals"                   # T007
```

---

## Implementation Strategy

### MVP (User Story 1)

1. Setup (T001) → Foundational (T002–T007) → US1 (T008–T009).
2. **STOP and VALIDATE**: bracketing connections render primary orange; no regression to the chain or rapid cycling.
3. Demo — the projected path is already distinguishable by color.

### Incremental

- Add US2 (T010–T011) → half-opacity dimming makes the orange path pop.
- Polish (T012–T013) → full suites + quickstart.

---

## Notes

- `RouteLayer` (candidate tour, primary, full opacity) is untouched — the emphasis set is candidate + its two highlighted connections. `WorkdayLayer` resolves `--primary` with its own local resolver (D3).
- `highlight` is a render hint only; it plays no part in lazy tracing.
- Colors stay role-named (`--primary` / `--route-neutral`) resolved from `app.css`; `0.5` is an opacity value, not a color literal (Constitution VI).
- Commit after each task or logical group.
