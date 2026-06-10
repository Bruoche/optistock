---

description: "Task list for Delivery Driver Assignment (006)"
---

# Tasks: Delivery Driver Assignment

**Input**: Design documents from `/specs/006-driver-assignment/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/driver-availability.md

**Tests**: Included — the constitution (I. Quality First) requires tests for behavior affecting correctness.

**Organization**: One user story (US1) — listing the drivers available for an optimized tour. US2
(selecting/assigning a driver) was explicitly deferred out of scope.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: US1
- Exact file paths included in each task.

## Path Conventions

Laravel + React monorepo: backend under `app/`, `database/`, `routes/`, `tests/`; frontend under
`resources/js/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Prerequisites for serving driver images.

- [X] T001 Ensure the public storage symlink exists for driver images: run `php artisan storage:link` (idempotent; backs the `image_url` accessor on the `public` disk per plan D5).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Persistence layer (schema, models, seed/factory) that the user story builds on.

**⚠️ CRITICAL**: No user-story work can begin until this phase is complete.

- [X] T002 Create migration creating `drivers`, `delivery_modes`, and `driver_delivery_mode` tables per data-model.md (drivers: name, nullable image_path, timestamps; delivery_modes: unique label, no timestamps; pivot: driver_id + delivery_mode_id FKs cascadeOnDelete, unique composite) in `database/migrations/2026_06_09_000001_create_driver_tables.php` (name reflects the 3 tables created).
- [X] T003 [P] Create the `App\Models\DeliveryMode` Eloquent model — `belongsToMany(Driver::class)`, `public $timestamps = false`, `$fillable = ['label']` — in `app/Models/DeliveryMode.php`.
- [X] T004 [P] Create the `App\Models\Driver` model — `belongsToMany(DeliveryMode::class)` (`deliveryModes`), `image_url` accessor (`Storage::disk('public')->url(image_path)` or null), and an `available(DeliveryModeEnum $mode)` scope (`whereHas('deliveryModes', label = $mode->value)`, eager-load `deliveryModes`, order by `name` asc; import `App\Enums\DeliveryMode as DeliveryModeEnum`) — in `app/Models/Driver.php`.
- [X] T005 Create `DeliveryModeSeeder` seeding exactly the three `App\Enums\DeliveryMode` backing values via idempotent `updateOrCreate(['label' => …])`, and register it in `database/seeders/DatabaseSeeder.php` — files `database/seeders/DeliveryModeSeeder.php`, `database/seeders/DatabaseSeeder.php`.
- [X] T006 [P] Create `DriverFactory` (faker name, sometimes-null `image_path`) with a `withModes()`/`configure()` afterCreating that attaches 1–3 distinct modes (at least one, per CR-1) in `database/factories/DriverFactory.php`.

**Checkpoint**: `php artisan migrate --seed` yields the three modes + a usable `Driver` factory.

---

## Phase 3: User Story 1 - See drivers that can run the optimized tour (Priority: P1) 🎯 MVP

**Goal**: After optimizing, the results page lists the drivers whose modes include the tour's mode — name
prominent + mode icons — in the old stop-list region; shows "No one available for this delivery." when none.

**Independent Test**: Seed drivers with mixed modes, optimize a tour for a given mode, confirm the results
page lists exactly the matching drivers (alphabetical, correct icons, placeholder for missing image) and shows
the empty message when no driver matches.

### Tests for User Story 1 (write first; ensure they FAIL before implementation) ⚠️

- [X] T007 [P] [US1] Feature test for `GET /api/tour/drivers`: unauthenticated → 401; returns only drivers whose modes include `mode`; alphabetical order by name; payload shape (`id`, `name`, `image_url` null vs URL, `modes[]`); invalid/missing `mode` → 422; no match → `{ "data": [] }` — in `tests/Feature/DriverAvailabilityTest.php`.
- [X] T008 [P] [US1] Unit test for the `Driver` model: `available` scope filters + orders correctly; `image_url` accessor (path → URL, null → null); and label↔enum parity (`delivery_modes` labels equal `DeliveryMode` enum values, CR-2) — in `tests/Unit/DriverTest.php`.
- [X] T009 [P] [US1] Frontend test for `DriverList` (mock `fetch`/`useTourDrivers`): renders names prominently + exactly each driver's mode icons; preserves API order; shows placeholder when `imageUrl` null; shows the spinner + "Checking available drivers…" while loading; renders "No one available for this delivery." on empty — in `resources/js/components/tour/driver-list.test.tsx`.

### Implementation for User Story 1

- [X] T010 [US1] Create `AvailableDriversRequest` validating `mode` is required and ∈ `App\Enums\DeliveryMode` (import aliased as `DeliveryModeEnum`; `Rule::enum`) in `app/Http/Requests/AvailableDriversRequest.php`.
- [X] T011 [US1] Create `DriverController@available(AvailableDriversRequest)` — cast the validated `mode` string to the enum via `DeliveryModeEnum::from()` (import `App\Enums\DeliveryMode as DeliveryModeEnum`), call `Driver::available($mode)`, return `{ "data": [ {id, name, image_url, modes} ] }` (map `deliveryModes` → label array); thin controller, no domain logic (mirrors `TourOptimizationController`) — in `app/Http/Controllers/DriverController.php`.
- [X] T012 [US1] Provide a shared read limiter and register the route: in `app/Providers/AppServiceProvider.php` rename the `tour-geometry` RateLimiter to `tour-read` (30/min, unchanged) and update the geometry route in `routes/api.php` to `throttle:tour-read`; then register `GET tour/drivers` → `DriverController@available` in the existing `auth` group with `throttle:tour-read`, name `tour.drivers`, in `routes/api.php`.
- [X] T013 [P] [US1] Add the `Driver` type (`id: number; name: string; imageUrl: string | null; modes: DeliveryMode[]`) to `resources/js/types/tour.ts`.
- [X] T014 [P] [US1] Create `useTourDrivers(mode)` hook — `fetch('/api/tour/drivers?mode=…', { credentials:'same-origin', Accept: json })`, map `image_url`→`imageUrl`, expose `{ drivers, status: 'loading'|'ready'|'error' }`; re-fetch when `mode` changes — in `resources/js/hooks/use-tour-drivers.ts`.
- [X] T015 [US1] Create `DriverList` component — a `MODE_ICON` map (walking→person/footprints, driving→car, trucking→truck from lucide-react); loading state = spinner + "Checking available drivers…"; rows with image/placeholder + prominent name + supported-mode icons beneath; empty state "No one available for this delivery."; inline error line (not silent blank); role-named colors, mirrors `stop-list.tsx` — in `resources/js/components/tour/driver-list.tsx` (depends on T013, T014).
- [X] T016 [US1] Update `ResultSummary` to accept `mode: DeliveryMode` and render `<DriverList mode={mode} />` in place of the reserved `flex-1` slot in `resources/js/components/tour/result-summary.tsx`; add/adjust a test asserting `ResultSummary` renders `DriverList` for the given mode and confirm the existing `resources/js/pages/tour/optimize.test.tsx` still passes with the new required prop (depends on T015).
- [X] T017 [US1] Pass the done-state `mode` (`state.mode`) from `optimize.tsx` into `<ResultSummary mode=… />` in `resources/js/pages/tour/optimize.tsx` (depends on T016).

**Checkpoint**: US1 fully functional — list, icons, placeholder, empty message, mode-refresh on re-optimize.

---

## Phase 4: Polish & Cross-Cutting Concerns

- [X] T018 [P] Add a small demo-driver seed (mixed modes, one without image) to make the list visible in dev, gated to non-production, in `database/seeders/DatabaseSeeder.php` (optional helper for quickstart).
- [X] T019 Run `php artisan test --filter='DriverAvailabilityTest|DriverTest|TourGeometryTest'` (geometry included to confirm the `tour-read` limiter rename) and `npm run test` (driver-list + result-summary + optimize) and confirm green; then walk `specs/006-driver-assignment/quickstart.md` end-to-end.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: none — can start immediately.
- **Foundational (Phase 2)**: after Setup; BLOCKS the user story.
- **User Story 1 (Phase 3)**: after Foundational.
- **Polish (Phase 4)**: after US1.

### Within User Story 1

- Tests T007–T009 written first and FAIL before implementation.
- Backend: T010 (request) → T011 (controller) → T012 (route).
- Frontend: T013 + T014 → T015 (list) → T016 (ResultSummary) → T017 (optimize wiring).
- Backend and frontend tracks are independent until T019.

### Parallel Opportunities

- Phase 2: T003, T004, T006 in parallel (different files); T002 first, T005 after models.
- Phase 3 tests: T007, T008, T009 in parallel.
- Frontend T013 + T014 in parallel; the backend chain (T010→T012) runs parallel to the frontend chain.

---

## Parallel Example: User Story 1

```bash
# Tests first, together:
Task: "Feature test GET /api/tour/drivers in tests/Feature/DriverAvailabilityTest.php"   # T007
Task: "Unit test Driver model in tests/Unit/DriverTest.php"                               # T008
Task: "Frontend DriverList test in resources/js/components/tour/driver-list.test.tsx"     # T009

# Then the two implementation tracks in parallel:
# Backend:  T010 → T011 → T012
# Frontend: (T013 + T014) → T015 → T016 → T017
```

---

## Implementation Strategy

### MVP = the whole feature (single story)

1. Phase 1 Setup → Phase 2 Foundational (`migrate --seed`).
2. Phase 3 US1 (tests fail → implement → pass).
3. Validate independently via quickstart.md.
4. Phase 4 polish + full suite green.

---

## Notes

- [P] = different files, no dependencies.
- Only one user story; US2 (assign a driver) and any time-related info are out of scope (spec).
- The lookup model is `App\Models\DeliveryMode`; the allowed-set enum is imported aliased as
  `DeliveryModeEnum` where both appear (plan D3).
- Verify tests fail before implementing; commit after each task or logical group.
