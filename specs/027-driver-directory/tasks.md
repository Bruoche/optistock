---
description: "Task list for Drivers Directory (feature 027)"
---

# Tasks: Drivers Directory

**Input**: Design documents from `/specs/027-driver-directory/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/drivers-directory.md, quickstart.md

**Tests**: INCLUDED — the spec defines an Independent Test per story and the constitution requires
tests for correctness-affecting behavior. Feature test (PHPUnit) for the endpoint + Vitest for the
hook/bar/page.

**Organization**: Grouped by user story. The shared endpoint + plumbing is Foundational (every story
needs it); each story then adds its slice of UI + its own tests.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no incomplete dependency)
- **[Story]**: US1 / US2 / US3
- Exact file paths included

## Path Conventions

Web app: Laravel backend at repo root (`app/`, `routes/`, `tests/`), React/Inertia frontend at
`resources/js/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm the ground rules before touching code.

- [ ] T001 Confirm scope from `specs/027-driver-directory/plan.md`: no new dependency, no migration, no existing endpoint touched; the `throttle:tour-read` limiter and the `Driver`/`Warehouse`/`DeliveryMode` models already exist and are reused.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The `GET /api/drivers` endpoint, the `/driver` page route, and the shared frontend
plumbing every user story depends on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

### Backend

- [ ] T002 [P] Create `DirectoryDriverData` DTO in `app/DTOs/DirectoryDriverData.php` — constructor takes a `Driver`; `toArray()` → `{ id, name, image_url, modes (deliveryModes->pluck('label')), warehouse_id, warehouse_name }` (no workday/road figures).
- [ ] T003 Add `scopeMatching(Builder $q, ?string $name, array $modes, ?int $warehouseId)` to `app/Models/Driver.php` (beside `scopeAvailable`): trimmed non-empty name → `whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($name).'%'])`; each mode → `whereHas('deliveryModes', label = mode)` (AND); non-null warehouse → `where('warehouse_id', $warehouseId)`; always `->with(['deliveryModes','warehouse'])->orderByRaw('LOWER(name)')` (case-insensitive, matching the spec's case-insensitive sort regardless of DB collation).
- [ ] T004 [P] Create `DriverDirectoryRequest` in `app/Http/Requests/DriverDirectoryRequest.php`: `authorize()` → user authenticated; rules `name` `nullable|string|max:255`, `modes` `nullable|array`, `modes.*` `Rule::enum(DeliveryMode)`, `warehouse` `nullable|integer|exists:warehouses,id`.
- [ ] T005 Create `DriverDirectoryService` in `app/Services/DriverDirectoryService.php` — `search(?string $name, array $modes, ?int $warehouseId): Collection` runs `Driver::matching(...)->get()` and maps each to `DirectoryDriverData` (depends on T002, T003).
- [ ] T006 Create `DriverDirectoryController` in `app/Http/Controllers/DriverDirectoryController.php` — thin `index(DriverDirectoryRequest, DriverDirectoryService)` returning `response()->json(['data' => $service->search(name, modes, warehouse)->map->toArray()->values()])` (depends on T004, T005).
- [ ] T007 Add a `directory(Request)` method to `app/Http/Controllers/DriverPageController.php` returning `Inertia::render('driver/directory', ['warehouses' => Warehouse::orderBy('name')->get(['id','name'])])`; leave `manage()` byte-for-byte unchanged.
- [ ] T008 Register `GET drivers → [DriverDirectoryController::class, 'index']` in `routes/api.php` inside the existing `auth` group with `->middleware('throttle:tour-read')->name('drivers.index')` — new line only (depends on T006).
- [ ] T009 Register `GET driver → [DriverPageController::class, 'directory']` in `routes/web.php` inside the `auth`+`verified` group with `->name('driver.directory.page')` — new line only, singular `driver`, distinct from `driver/{driver}` (depends on T007).
- [ ] T010 [P] Create `tests/Feature/DriverDirectoryTest.php` with the endpoint baseline: authenticated request with no params lists **all** drivers name-sorted (assert case-insensitive order — seed at least one mixed-case pair, e.g. `bruno`/`Amelie`, to prove `LOWER(name)` ordering) with the documented row shape; unknown `warehouse` and an invalid `modes[]` value each → 422 (depends on T008).

### Frontend

- [ ] T011 [P] Add the `DirectoryDriver` type to `resources/js/types/driver.ts` (`{ id, name, imageUrl, modes, warehouseId, warehouseName }`); reuse existing `WarehouseOption`.
- [ ] T012 [P] Create `use-drivers-directory` hook in `resources/js/hooks/use-drivers-directory.ts` — input `{ name, modes, warehouseId }`; builds `/api/drivers?…` query (omit blank criteria), `fetch` same-origin, debounce the `name` term (~200ms), cancel the prior request, commit only the response matching the current criteria (settle-on-latest), map `image_url`/`warehouse_*` → camelCase; returns `{ drivers, status: 'loading'|'ready'|'error' }` (depends on T011).
- [ ] T013 [P] Extract `DriverSummary` presentational component in `resources/js/components/driver/driver-summary.tsx` — avatar/`UserRound` placeholder + name + mode icons + `Warehouse` warehouse line, lifted verbatim from `driver-list.tsx`. **Move** the `MODE_ICON` and `MODE_LABEL` maps here (single source) and export them; `DriverSummary` owns the icon rendering. Props are a minimal identity subset (`name`, `imageUrl`, `modes`, `warehouseName`) so both `Driver` and `DirectoryDriver` satisfy it. Role-named palette only.
- [ ] T014 Refactor `resources/js/components/tour/driver-list.tsx` to render `<DriverSummary>` for the identity block (importing `MODE_ICON`/`MODE_LABEL` from `driver-summary.tsx`, deleting the local copies — no duplicated map, Constitution VI), keeping its existing figures — behavior-preserving; existing `driver-list.test.tsx` stays green (name, warehouse text, `getByLabelText('Walking')`, single `img` assertions) (depends on T013).

**Checkpoint**: Endpoint + page route + hook + shared row exist. User stories can begin.

---

## Phase 3: User Story 1 - Browse all drivers and open one to manage (Priority: P1) 🎯 MVP

**Goal**: The `/driver` page lists every driver (default criteria), name-sorted, each row with
picture/placeholder + name + modes + warehouse, with loading/error/empty states; clicking a row
opens `/driver/{id}`.

**Independent Test**: Seed several drivers, open `/driver` → all appear sorted with correct
presentation; click a row → its management page; empty DB → the exact empty-state text; while
loading → a spinner.

- [ ] T015 [US1] Create `resources/js/components/driver/directory-bar.tsx` — a `flex-wrap` bar container (role-named palette, no horizontal overflow) taking `criteria` + `onChange` + `warehouses` props; renders the bar frame with empty control slots for now (name/modes/warehouse controls arrive in US2/US3).
- [ ] T016 [US1] Create `resources/js/pages/driver/directory.tsx` — reads the Inertia `warehouses` page prop and forwards it to `<DirectoryBar>`; holds `criteria` state `{ name: '', modes: [], warehouseId: null }`, renders `<DirectoryBar>` above a list region, consumes `use-drivers-directory`: `loading` → `Loader2` spinner, `error` → a retrievable error message, `ready`+0 rows → the exact text `no drivers found with current criterias.`, else the rows (depends on T012, T015).
- [ ] T017 [US1] In `resources/js/pages/driver/directory.tsx`, render each row as a link to `driver.manage.page` (`/driver/{id}`) wrapping `<DriverSummary>` (depends on T016, T013).
- [ ] T018 [P] [US1] Vitest `resources/js/pages/driver/directory.test.tsx` — rows render name-sorted, no-image → placeholder, empty-state exact text, loading + error states, row anchors to `/driver/{id}` (mock the hook) (depends on T017).

**Checkpoint**: MVP — the directory browses and links out, fully testable on its own.

---

## Phase 4: User Story 2 - Find drivers by name (Priority: P2)

**Goal**: A name field narrows the list to partial, case-insensitive name matches; clearing it
restores all; rapid typing settles on the latest term.

**Independent Test**: With `Sacha Brook`, `Charline Klein`, `Hector Chard`, `Diego Ruiz`, type `cha`
→ first three; clear → all four.

- [ ] T019 [US2] Add a name `<input>` to `resources/js/components/driver/directory-bar.tsx`, wired to `criteria.name` via `onChange` (role-named palette; accessible label) (depends on T015).
- [ ] T020 [US2] Verify/finish the name behavior in `resources/js/hooks/use-drivers-directory.ts`: ~200ms debounce on the term, whitespace-only treated as empty (no name filter), stale-request cancellation prevents flicker (depends on T012).
- [ ] T021 [P] [US2] Add name-filter cases to `tests/Feature/DriverDirectoryTest.php`: `name=cha` → Sacha/Charline/Hector and excludes Diego; case-insensitive (`CHA` matches); blank/whitespace → all (depends on T010).
- [ ] T022 [US2] Vitest `resources/js/components/driver/directory-bar.test.tsx` — typing in the name input emits the updated `criteria.name` (depends on T019).

**Checkpoint**: Name search works end-to-end, independently testable.

---

## Phase 5: User Story 3 - Narrow by required modes and warehouse (Priority: P3)

**Goal**: A modes multi-selector (AND semantics) and an optional warehouse selector further narrow
the list; clearing all restores everyone.

**Independent Test**: Seed varied mode sets/warehouses; select two modes → only drivers having both;
add a warehouse → narrows to it; deselect/clear → full list.

- [ ] T023 [US3] Add a delivery-mode multi-toggle to `resources/js/components/driver/directory-bar.tsx` (options from `DELIVERY_MODES`, icons from the shared `MODE_ICON`; toggles membership in `criteria.modes`) (depends on T015).
- [ ] T024 [US3] Add a warehouse `<Select>` to `resources/js/components/driver/directory-bar.tsx` — the `warehouses` prop plus an "Any warehouse" option, wired to `criteria.warehouseId` (`null` = any) (depends on T023).
- [ ] T025 [P] [US3] Add mode/warehouse cases to `tests/Feature/DriverDirectoryTest.php`: one mode filters to that mode; two modes → only drivers with **both** (AND); `warehouse` → only that warehouse; name+modes+warehouse combined → conjunctive result (depends on T010).
- [ ] T026 [US3] Extend `resources/js/components/driver/directory-bar.test.tsx` — toggling two modes emits both in `criteria.modes`; selecting a warehouse emits its id; "Any warehouse" emits `null` (depends on T024, T022).

**Checkpoint**: All three criteria combine conjunctively; each story independently functional.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [ ] T027 [P] Run `npm run format:check`, `npm run lint`, `npm run types` and fix any issue (prettier is separate from eslint — run both).
- [ ] T028 [P] Run `php artisan test --filter=DriverDirectory` and `npm run test -- use-drivers-directory directory-bar directory driver-list` — all green (`driver-list` re-run guards the `DriverSummary` extraction; `DriverSummary` itself is covered transitively by `directory`/`driver-list`).
- [ ] T029 Verify role-named palette only (no one-off colours, FR-016), minimal comments (only the non-inferable `LOWER LIKE` constraint), and no duplicated visual rule (`DriverSummary` is the single row-identity source) across the new files.
- [ ] T030 Manual quickstart.md walkthrough: open `/driver` seeded → all sorted; `cha` → three; two modes → AND; warehouse → narrows; clear → all; no-match → exact empty-state text; row → `/driver/{id}`; resize 320–2560px → no horizontal overflow.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none.
- **Foundational (Phase 2)**: after Setup — BLOCKS all user stories.
- **User Stories (Phase 3–5)**: all depend on Foundational. US1 is the MVP; US2/US3 add UI slices
  onto the shared bar + already-complete endpoint, and are independently testable.
- **Polish (Phase 6)**: after the desired stories.

### Story Dependencies

- **US1 (P1)**: needs only Foundational.
- **US2 (P2)**: needs Foundational; edits the bar created in US1 (T015) — sequence US1 bar first.
- **US3 (P3)**: needs Foundational; also edits the US1 bar. Its bar test (T026) extends the US2 bar
  test file, so US2 precedes US3 for `directory-bar.test.tsx`.

### Within Foundational

- T002/T004/T011/T012/T013 are independent ([P]). T003 before T005; T005 before T006; T006 before
  T008; T007 before T009; T013 before T014; T008 before T010.

### Parallel Opportunities

- Foundational: T002, T004, T011, T012, T013 in parallel; T010 after T008.
- The backend feature-test additions (T021, T025) touch the same file across different phases — run
  in phase order, not concurrently with each other.
- Bar edits (T015/T019/T023/T024) all touch `directory-bar.tsx` — sequential.

---

## Parallel Example: Foundational

```bash
# Independent foundational tasks together:
Task: "T002 Create DirectoryDriverData DTO in app/DTOs/DirectoryDriverData.php"
Task: "T004 Create DriverDirectoryRequest in app/Http/Requests/DriverDirectoryRequest.php"
Task: "T011 Add DirectoryDriver type in resources/js/types/driver.ts"
Task: "T012 Create use-drivers-directory hook in resources/js/hooks/use-drivers-directory.ts"
Task: "T013 Extract DriverSummary in resources/js/components/driver/driver-summary.tsx"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup → Phase 2 Foundational (endpoint + route + hook + shared row).
2. Phase 3 US1 → **STOP & VALIDATE**: `/driver` lists all drivers, states work, rows link out.
3. Demo the MVP.

### Incremental Delivery

- US1 (browse) → US2 (name search) → US3 (modes + warehouse). Each adds value without breaking the
  prior; the backend endpoint already honours every criterion, so US2/US3 are UI + tests.

---

## Notes

- **Guardrail**: entirely additive. `GET /api/tour/drivers`, `/api/driver/{driver}/day`, update,
  tour-order, optimize/status/force/geometry/assign remain frozen and untouched. No migration, no
  new dependency.
- Empty-state text is verbatim: `no drivers found with current criterias.`.
- Never a silent empty list — loading / error / no-match are distinct states.
- [P] = different files, no incomplete dependency. Commit after each task or logical group.
