---
description: "Task list for Driver Schedule Filtering & Selected-Weekday Label"
---

# Tasks: Driver Schedule Filtering & Selected-Weekday Label

**Input**: Design documents from `specs/011-weekday-label/`

**Prerequisites**: plan.md, spec.md (011 + 010), research.md, data-model.md, contracts/driver-availability.md

**Tests**: Included — the constitution mandates tests for correctness-affecting behavior (plan Constitution Check).

**Organization**: By user story. Scope combines the schedule-filtering behavior (spec 010) and the weekday label (spec 011), implemented as one increment on the feature-006 driver list.

**User stories** (task organization):
- **US1 (P1)** — Only drivers who work the selected day are listed (backend schedule filter, wired end-to-end with the date defaulting to today). 🎯 MVP
- **US2 (P1)** — The date is visible & editable on the presentation phase; the list refreshes on every change.
- **US3 (P2)** — A small text beside the date names the selected weekday and updates with it.

## Path Conventions

Web app (Laravel + React SPA): backend under `app/`, `database/`, `routes/`, `tests/`; frontend under `resources/js/`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Confirm prerequisites; no new dependencies expected (Laravel 12, React 19, shadcn/ui, lucide-react already present).

- [ ] T001 Confirm the shadcn `Input` primitive exists at `resources/js/components/ui/input.tsx` (used by the date field); if absent, add it via the project's shadcn setup. No other new dependencies.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The weekday enum, lookup table, pivot, model relation, and seed/fixture data that every user story depends on.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [ ] T002 [P] Create `App\Enums\WeekDay` in `app/Enums/WeekDay.php` — seven string-backed cases (`monday`…`sunday`) plus `public static function fromDate(\Carbon\CarbonInterface $date): self` mapping `$date->dayOfWeekIso` (1=Monday…7=Sunday) to a case (mirrors `App\Enums\DeliveryMode`).
- [ ] T003 Create migration `database/migrations/2026_07_01_000001_create_week_day_tables.php` — `week_days` (`id`, `label` unique) and `driver_week_day` pivot (`id`, `driver_id` FK cascadeOnDelete, `week_day_id` FK cascadeOnDelete, unique composite `(driver_id, week_day_id)`); `down()` drops pivot then `week_days` (mirrors `create_driver_tables.php`).
- [ ] T004 [P] Create `App\Models\WeekDay` in `app/Models/WeekDay.php` — `#[Fillable(['label'])]`, `$timestamps = false`, `drivers(): belongsToMany(Driver::class, 'driver_week_day')` (mirrors `App\Models\DeliveryMode`).
- [ ] T005 [US-shared] Add `weekDays(): BelongsToMany` relation to `app/Models/Driver.php` — `belongsToMany(WeekDay::class, 'driver_week_day')` (alongside the existing `deliveryModes`).
- [ ] T006 [P] Create `database/seeders/WeekDaySeeder.php` — idempotent `WeekDay::updateOrCreate(['label' => $day->value])` over `WeekDayEnum::cases()` (mirrors `DeliveryModeSeeder`).
- [ ] T007 Register the seeder in `database/seeders/DatabaseSeeder.php` — `$this->call(WeekDaySeeder::class)` before the demo seeder.
- [ ] T008 [P] Extend `database/factories/DriverFactory.php` — in `configure()` also `afterCreating` sync a random **non-empty** subset (1–7) of week days; add `withDays(array $labels): static` for deterministic fixtures (mirrors the mode `withModes`/`modeIds` helpers, resolving day labels to `week_days` ids).
- [ ] T009 [P] Extend `database/seeders/DriverDemoSeeder.php` — add a `days` schedule per demo driver spanning the variety in spec 010 (Mon–Fri, weekend-only, a 4-day week, all-week) and sync them after create.

**Checkpoint**: DB has `week_days` + `driver_week_day`; drivers can be given schedules; foundation ready.

---

## Phase 3: User Story 1 - Filter drivers by the selected day's weekday (Priority: P1) 🎯 MVP

**Goal**: `GET /api/tour/drivers` requires a `date`, deduces its weekday server-side, and returns only drivers whose modes include the tour mode **and** whose schedule includes that weekday; the results-page list is wired to send the date (defaulting to today).

**Independent Test**: Seed drivers with known schedules; call the endpoint with `mode` + a date on a known weekday and confirm only mode-matching, that-weekday drivers return; a weekend date returns only weekend drivers; missing/invalid `date` → 422.

### Tests for User Story 1 ⚠️ (write first, ensure they FAIL)

- [ ] T010 [P] [US1] `tests/Unit/WeekDayTest.php` — `WeekDay::fromDate` returns the correct case for a fixed date on each of the 7 weekdays; `week_days` seeded labels match `WeekDay::cases()` values (label↔enum parity).
- [ ] T011 [P] [US1] Extend `tests/Feature/DriverAvailabilityTest.php` — `date` required (missing → 422; non-date → 422); with `mode`+`date`, only drivers matching mode AND the date's weekday returned; a fixed Saturday date returns only weekend-scheduled drivers; a weekday date excludes weekend-only drivers; empty schedule never listed; payload shape (`id`, `name`, `image_url`, `modes`) unchanged; still ordered by `name`; unauth → 401.
- [ ] T012 [P] [US1] Extend `tests/Unit/DriverTest.php` — `Driver::available($mode, $day)` scope returns drivers matching both relations and excludes drivers missing either.

### Implementation for User Story 1

- [ ] T013 [US1] Extend `scopeAvailable` in `app/Models/Driver.php` to `scopeAvailable(Builder $query, DeliveryModeEnum $mode, WeekDayEnum $day)` — add `->whereHas('weekDays', fn (Builder $days) => $days->where('label', $day->value))` to the existing mode `whereHas`; keep eager-load `deliveryModes` and `orderBy('name')`.
- [ ] T014 [US1] Add `date` validation to `app/Http/Requests/AvailableDriversRequest.php` — `'date' => ['required', 'date']` (keep the `mode` enum rule); add a `date` message.
- [ ] T015 [US1] Update `app/Http/Controllers/DriverController.php@available` — resolve `$day = WeekDayEnum::fromDate($request->date('date'))` and call `Driver::available($mode, $day)`; response mapping unchanged.
- [ ] T016 [US1] Update `resources/js/hooks/use-tour-drivers.ts` — accept `date: string`, add `&date=${encodeURIComponent(date)}` to the fetch URL, key the effect + stale-guard on `(mode, date)` so no stale list is reported.
- [ ] T017 [US1] Update `resources/js/components/tour/driver-list.tsx` — accept a `date` prop and pass it to `useTourDrivers(mode, date)` (rendering unchanged).
- [ ] T018 [US1] Update `resources/js/components/tour/result-summary.tsx` — accept `date` and forward it to `<DriverList mode date />`.
- [ ] T019 [US1] Update `resources/js/pages/tour/optimize.tsx` — add `const [tourDate, setTourDate] = useState(() => new Date().toLocaleDateString('sv-SE'))` beside `mode`/`loop` (retained across `reset`); `'sv-SE'` yields a **local** `YYYY-MM-DD` (no UTC off-by-one, matching the local-noon label parse in R7). Pass `date={tourDate}` to `<ResultSummary />`.
- [ ] T019a [US1] Update `resources/js/components/tour/result-summary.test.tsx` — pass the new required `date` prop wherever `ResultSummary` is rendered so the existing suite stays green after T018 (`onDateChange` does not exist yet — added in US2). Assert the driver list still renders.

**Checkpoint**: Endpoint filters by mode + weekday; results-page list reflects today's weekday; existing result-summary test green. US1 independently testable.

---

## Phase 4: User Story 2 - Editable date on the presentation phase + live refresh (Priority: P1)

**Goal**: The presentation phase shows an editable date field (default today, persists across "New tour"); changing it refreshes the driver list for the new date with no stale rows.

**Independent Test**: On the result view, confirm the date field shows today and is editable; change it to another weekday and confirm the list refreshes to that day's eligible set; reset ("New tour") and confirm the date is retained.

### Tests for User Story 2 ⚠️

- [ ] T020 [P] [US2] `resources/js/components/tour/tour-date-field.test.tsx` — renders a date input defaulting to the given value; changing it calls `onDateChange` with the new `YYYY-MM-DD`.
- [ ] T021 [P] [US2] Extend `resources/js/components/tour/driver-list.test.tsx` — mock `fetch`; changing the `date` prop triggers a re-fetch with the new `date` query param and re-renders without stale rows.

### Implementation for User Story 2

- [ ] T022 [US2] Create `resources/js/components/tour/tour-date-field.tsx` — a shadcn `Input type="date"` bound to `date`, calling `onDateChange(value)` on change; role-named color vars, no raw hex (constitution VI). (Weekday label added in US3.)
- [ ] T023 [US2] Render `<TourDateField date={date} onDateChange={onDateChange} />` above `<DriverList />` in `resources/js/components/tour/result-summary.tsx`; add `onDateChange` to its props. Update `resources/js/components/tour/result-summary.test.tsx` to pass a (no-op or spy) `onDateChange` for the now-required prop.
- [ ] T024 [US2] Wire `onDateChange` in `resources/js/pages/tour/optimize.tsx` to `setTourDate` and pass it to `<ResultSummary />`; confirm `reset()` does not clear `tourDate`.

**Checkpoint**: Date is editable on the presentation phase; the list refreshes on change; date persists across reset. US1 + US2 both work.

---

## Phase 5: User Story 3 - Selected-weekday label (Priority: P2)

**Goal**: A small read-only text beside the date names the selected date's weekday and updates whenever the date changes; it always matches the weekday used to filter.

**Independent Test**: For a date on a known weekday, the label shows that weekday name; change the date to a different weekday and confirm the label updates; never blank.

### Tests for User Story 3 ⚠️

- [ ] T025 [P] [US3] Extend `resources/js/components/tour/tour-date-field.test.tsx` — the weekday label shows the correct day name for a fixed date, updates when the date changes, and is never empty; a date near a timezone boundary is not off-by-one.

### Implementation for User Story 3

- [ ] T026 [P] [US3] Add a `formatWeekday(date: string): string` helper (in `resources/js/types/tour.ts` or colocated in the component) — parse `YYYY-MM-DD` as a **local** calendar date (construct at local noon to avoid TZ rollover) and return `toLocaleDateString(undefined, { weekday: 'long' })`.
- [ ] T027 [US3] Render the weekday label beside the input in `resources/js/components/tour/tour-date-field.tsx` using `formatWeekday(date)`, styled as muted-foreground read-only text; recomputes on every `date` change.

**Checkpoint**: All three stories functional; the label's weekday matches the server's filtering weekday (spec 011 SC-004).

---

## Phase 6: Polish & Cross-Cutting Concerns

- [ ] T028 [P] Update `specs/006-driver-assignment/contracts/driver-availability.md` reference note (or add a pointer) so the `date`-param change is discoverable; ensure `contracts/driver-availability.md` (011) is the current source.
- [ ] T029 Run `php artisan test` and `npm run test` — all backend + frontend suites green.
- [ ] T030 Run lint/format (`./vendor/bin/pint`, `npm run lint`, `npm run types`) and fix any issues.
- [ ] T031 Execute `specs/011-weekday-label/quickstart.md` end-to-end (migrate:fresh --seed, optimize, default-today label, change to weekend/weekday, empty case).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies.
- **Foundational (Phase 2)**: after Setup — BLOCKS all user stories (enum, table, pivot, relation, seeders, factory).
- **US1 (Phase 3)**: after Foundational. Delivers the backend filter + end-to-end wiring (MVP).
- **US2 (Phase 4)**: after US1 (reuses the `date` plumbing from T016–T019). Adds the editable field.
- **US3 (Phase 5)**: after US2 (extends `TourDateField`). Adds the label.
- **Polish (Phase 6)**: after all desired stories.

### User Story Dependencies

- **US1 (P1)**: depends only on Foundational. Independently testable via the API + list.
- **US2 (P1)**: builds on US1's `date` plumbing; independently testable at the UI (edit + refresh).
- **US3 (P2)**: builds on US2's `TourDateField`; independently testable (label value/updates).

### Within Each User Story

- Tests written first and failing before implementation.
- Backend model/scope before controller/request; frontend hook before components that consume it.

### Parallel Opportunities

- Foundational: T002, T004, T006, T008, T009 are `[P]` (different files); T003 (migration) and T005/T007 (edits to Driver/DatabaseSeeder) gate on their targets.
- US1 tests T010–T012 are `[P]` (separate test files); write together before implementation.
- Backend (T013–T015) and frontend (T016–T019) of US1 can proceed in parallel once tests exist.

---

## Parallel Example: User Story 1

```bash
# Tests together (separate files):
Task: "WeekDayTest in tests/Unit/WeekDayTest.php"
Task: "DriverAvailabilityTest date+weekday cases in tests/Feature/DriverAvailabilityTest.php"
Task: "Driver available-scope day filter in tests/Unit/DriverTest.php"

# Then backend and frontend wiring in parallel:
Task: "Extend scopeAvailable + request + controller (app/Models/Driver.php, app/Http/...)"
Task: "Wire date through hook + components (resources/js/hooks + components/tour)"
```

---

## Implementation Strategy

### MVP First (User Story 1)

1. Phase 1 Setup → Phase 2 Foundational (CRITICAL) → Phase 3 US1.
2. **STOP and VALIDATE**: endpoint filters by mode + weekday; list reflects today. Demo the MVP.

### Incremental Delivery

1. Foundational ready → US1 (filter, MVP) → US2 (editable date + refresh) → US3 (weekday label).
2. Each story adds value without breaking the prior; commit after each checkpoint.

---

## Notes

- `[P]` = different files, no incomplete-task dependency.
- `[US-shared]` on T005 marks a foundational edit to a file US1 also touches — do it in Phase 2 so stories stay independent.
- Backend owns the weekday deduction; the front computes it only for the label (research R3) — do not send a weekday to the API.
- Reuse feature 006's empty state message "No one available for this delivery." (no new copy).
- Commit after each task or logical group; stop at any checkpoint to validate.
