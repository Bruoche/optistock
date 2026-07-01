# Implementation Plan: Driver Schedule Filtering & Selected-Weekday Label

**Branch**: `011-weekday-label` | **Date**: 2026-07-01 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/011-weekday-label/spec.md`, extending
`specs/010-driver-schedule-filtering/spec.md` and building on
`specs/006-driver-assignment/plan.md`.

## Summary

Feature 011 enhances the driver filtering introduced in feature 006 with a **date
dimension**, the same way the loop toggle (004) added a field to the tour flow.
Each driver gains a **weekly schedule** — the set of weekdays they are allowed to
work — stored relationally as a shared `week_days` lookup table plus a
`driver_week_day` pivot (mirroring the `delivery_modes` / `driver_delivery_mode`
shape from 006). On the **presentation phase** (the `done` state → `ResultSummary`),
a date field appears and is editable; a small text beside it names the selected
date's weekday; changing the date refreshes the available-driver list.

The available-drivers endpoint (`GET /api/tour/drivers`) now **requires a `date`**
alongside `mode`. The **backend deduces the weekday from that date** (authoritative,
locale-independent via Carbon `dayOfWeekIso`) and filters to drivers whose schedule
includes it, **in addition to** the existing mode match. The frontend also computes
the weekday independently — but only to render the label; the backend never trusts
the front's calculation. This keeps the door open for further date-based filters
(e.g. driver paid-time-off) without the front's weekday math being able to break
back-end logic.

## Technical Context

**Stack**: Laravel 12 (PHP) backend + React 19 + Inertia + Tailwind v4 + shadcn/ui
frontend. (Unchanged from 006.)

**Storage**: existing app database (MySQL/SQLite per env). New `week_days` lookup +
`driver_week_day` pivot alongside the existing `drivers` / `delivery_modes` tables.

**Testing**: PHPUnit (`Tests\TestCase` + `RefreshDatabase`) for backend; Vitest +
Testing Library for frontend.

**Request style**: the tour UI talks to `/api/*` JSON endpoints via `fetch`; the
drivers fetch (`use-tour-drivers.ts`) gains a `date` query param and re-fetches when
either `mode` or `date` changes.

**Current touch points**:
- `app/Models/Driver.php` — `belongsToMany(DeliveryMode)` + `scopeAvailable(mode)`;
  both extended for weekdays.
- `app/Http/Controllers/DriverController.php` — pure HTTP translation over the scope.
- `app/Http/Requests/AvailableDriversRequest.php` — validates `mode`; add `date`.
- `database/migrations/2026_06_09_000001_create_driver_tables.php` — existing driver
  tables (a new migration is added; this one is untouched).
- `resources/js/hooks/use-tour-drivers.ts`, `components/tour/driver-list.tsx`,
  `components/tour/result-summary.tsx`, `pages/tour/optimize.tsx`, `types/tour.ts`.

**Project Type**: web app (Laravel + React SPA).

**Performance/Scale**: trivial — two indexed `whereHas` lookups over small tables;
one `GET` per shown result or date change.

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — new lookup table, pivot, model relation, enum,
  scope change, and API `date` param all covered: backend Feature test (auth; mode
  **and** weekday filtering; a known weekend vs weekday date; missing/invalid date →
  422; payload shape) + Unit test (`WeekDay::fromDate` ISO mapping; label↔enum
  parity; `available` scope with day) + frontend test (weekday label value + updates
  on date change; refetch on date change; default = today). PASS.
- **II/III. Readable & Simple** — reuses the exact 006 relational pattern (shared
  lookup + pivot + `belongsToMany`), one enum owning the day set, one added scope
  argument, one small `TourDateField` presentational component. No new mechanism. PASS.
- **IV. Robustness** — `date` validated (`required|date`) → 422 on bad input; the
  weekday is derived server-side so a bad front-end calculation cannot corrupt the
  query; the fetch keeps explicit loading / empty / error states and never fails
  silently; failures logged server-side on the failure path. PASS.
- **V. Performance with Clarity** — indexed FK `whereHas` on both relations; no N+1
  (eager-load `deliveryModes`, and `weekDays` only if surfaced). PASS.
- **VI. Consistent, Reusable Styling** — the date field uses the shared shadcn input
  primitive and role-named color vars; the weekday label reuses the muted-foreground
  text style; no raw hex. PASS.

No violations. (Complexity Tracking table omitted — nothing to justify.)

## Decisions

- **D1 — `week_days` shared lookup + `driver_week_day` pivot (mirror of 006's D1).**
  A `week_days` table (autoincrement `id` + unique string `label`) and a
  `driver_week_day` pivot with a unique composite `(driver_id, week_day_id)`. A
  driver's schedule is the set of linked days. This is the same relational shape the
  user chose for modes, kept consistent for readability. `Driver`
  `belongsToMany(WeekDay::class, 'driver_week_day')`.

- **D2 — All seven days seeded (Mon–Sun); confirmed with user.** The plan input said
  "monday to friday", but spec 010 explicitly requires "week-end only", "a 4 day
  week", and "any other combination". Resolved with the user to seed all seven days
  so every schedule is representable. `WeekDaySeeder` inserts the seven rows
  idempotently (`updateOrCreate` on `label`), called from `DatabaseSeeder`.

- **D3 — `App\Enums\WeekDay` owns the day set; `App\Models\WeekDay` is the lookup
  row (mirror of 006's D3).** The enum is the authoritative set of seven cases with
  string backing values (`monday`…`sunday`); the table mirrors those values. Where
  both are referenced, import the enum aliased — `use App\Enums\WeekDay as
  WeekDayEnum` — keeping each symbol unambiguous. `week_days.label` values equal the
  enum backing values (parity guarded by a unit test), so no translation layer.

- **D4 — Backend deduces the weekday from the date (authoritative).**
  `WeekDayEnum::fromDate(CarbonInterface $date)` maps `dayOfWeekIso` (1 = Monday … 7
  = Sunday) to a case — locale- and timezone-independent. The controller derives the
  day from the validated `date` and passes it to the scope; it never accepts a
  weekday from the client. Rationale (user): future date-based filters (e.g. PTO) and
  resilience — a front-end weekday miscalculation can only mislabel the UI, never
  change which drivers the back end returns.

- **D5 — `GET /api/tour/drivers?mode=<mode>&date=<YYYY-MM-DD>`.** `date` is now
  **required** (`AvailableDriversRequest`: `required|date`). The controller resolves
  `WeekDayEnum::fromDate(...)` and calls the extended
  `Driver::available($mode, $day)` scope: `whereHas('deliveryModes', label = mode)`
  **and** `whereHas('weekDays', label = day->value)`, eager-load `deliveryModes`,
  order by `name`. Response shape is unchanged from 006 (`{ data: [ { id, name,
  image_url, modes } ] }`). Auth + `throttle:tour-read` unchanged.

- **D6 — Empty schedule is allowed.** A driver with no linked days never appears for
  any date (spec 010 edge case). No DB min-days constraint (the pivot cannot express
  "≥1" anyway); the factory attaches ≥1 day for useful fixtures, but the empty case
  is valid and tested.

- **D7 — Presentation-phase date state in `optimize.tsx`, default today, persists
  across reset.** A `tourDate` state (`YYYY-MM-DD`, initialized to the local current
  date) lives beside `mode`/`loop` in `optimize.tsx` and is passed into
  `ResultSummary`. It is retained across "New tour" (reset), matching how `mode` and
  `loop` persist. The date field is rendered on the presentation phase only; the
  tour-creation-menu date field (spec 009) is a separate, not-yet-implemented feature
  and is **out of scope** here.

- **D8 — `TourDateField` component: date input + weekday label.** A small
  presentational component (shadcn input, `type=date`) shown in `ResultSummary`, with
  the selected date's weekday name beside it (`toLocaleDateString(…, { weekday:
  'long' })`). To avoid timezone rollover the `YYYY-MM-DD` value is parsed as a local
  calendar date (construct at local noon) so the label's weekday matches the back
  end's. Changing the date lifts up via `onDateChange`, updating `tourDate` and
  triggering the drivers re-fetch.

- **D9 — `use-tour-drivers.ts` keys on `(mode, date)`.** The hook takes `mode` and
  `date`, sends both query params, and re-fetches whenever either changes; the
  stale-guard is extended so the reported list matches the current `(mode, date)`
  pair (no stale entries — spec 010 SC-002).

## Project Structure (feature-specific)

Backend — **new**:
- `app/Enums/WeekDay.php` — seven cases (`monday`…`sunday`); `fromDate()` via
  `dayOfWeekIso`.
- `app/Models/WeekDay.php` — the lookup row; `belongsToMany(Driver::class,
  'driver_week_day')`; `$timestamps = false`.
- `database/migrations/<ts>_create_week_day_tables.php` — `week_days` +
  `driver_week_day`.
- `database/seeders/WeekDaySeeder.php` — the seven day rows (idempotent); called from
  `DatabaseSeeder`.

Backend — **change**:
- `app/Models/Driver.php` — add `weekDays(): belongsToMany(WeekDay::class,
  'driver_week_day')`; extend `scopeAvailable(Builder, DeliveryModeEnum $mode,
  WeekDayEnum $day)` with the weekday `whereHas`.
- `app/Http/Controllers/DriverController.php` — resolve `WeekDayEnum::fromDate($date)`
  and pass it to the scope.
- `app/Http/Requests/AvailableDriversRequest.php` — add `date => required|date`.
- `database/seeders/DatabaseSeeder.php` — call `WeekDaySeeder`.
- `database/factories/DriverFactory.php` — attach a random non-empty weekday set;
  add `withDays(array $labels)` for deterministic fixtures.
- `database/seeders/DriverDemoSeeder.php` — give each demo driver a schedule (a mix:
  Mon–Fri, weekend-only, a 4-day week, all-week) so filtering is exercisable.

Frontend — **new**:
- `resources/js/components/tour/tour-date-field.tsx` — date input + selected-weekday
  label.

Frontend — **change**:
- `resources/js/hooks/use-tour-drivers.ts` — accept `date`; send `&date=`; re-fetch
  on `(mode, date)`.
- `resources/js/components/tour/driver-list.tsx` — accept `date`; pass to the hook.
- `resources/js/components/tour/result-summary.tsx` — take `date` + `onDateChange`;
  render `<TourDateField />` above `<DriverList />`.
- `resources/js/pages/tour/optimize.tsx` — hold `tourDate` state (default today,
  persists across reset); pass to `ResultSummary`.
- `resources/js/types/tour.ts` — (optional) a `WeekDay` display type / a
  `formatWeekday` helper if not colocated in the component.

Tests:
- `tests/Feature/DriverAvailabilityTest.php` — extend: `date` required (missing →
  422); mode **and** weekday filtering; a fixed weekend date returns only weekend
  drivers and a weekday date only weekday drivers; empty schedule never listed;
  payload shape unchanged.
- `tests/Unit/WeekDayTest.php` — `WeekDay::fromDate` ISO mapping for all 7 days;
  label↔enum parity with the seeded rows.
- `tests/Unit/DriverTest.php` — extend the `available` scope test for the day filter.
- `resources/js/components/tour/tour-date-field.test.tsx` — weekday label matches the
  date; updates when the date changes; default value is today.
- `resources/js/components/tour/driver-list.test.tsx` — re-fetches / re-renders when
  `date` changes (mock fetch), no stale rows.

Out of scope:
- Tour-creation-menu date field, default-today-on-load, and cross-tour persistence on
  the edit page (spec 009) — separate feature.
- Per-date exceptions (holidays, PTO/vacation overrides) — a future date-based filter
  the server-side deduction is designed to accommodate.
- A driver schedule-management UI (schedules are seeded data).

## Flow

1. Tour reaches `done` → `optimize.tsx` renders `ResultSummary` with the tour's
   `mode` and the current `tourDate` (default = local today).
2. `ResultSummary` renders `<TourDateField date onChange>` (input + weekday label)
   and `<DriverList mode date>`.
3. `use-tour-drivers(mode, date)` fetches
   `GET /api/tour/drivers?mode=<mode>&date=<YYYY-MM-DD>` (`status: loading`).
4. Backend: `AvailableDriversRequest` validates `mode` + `date`;
   `DriverController@available` resolves `WeekDayEnum::fromDate($date)` and runs
   `Driver::available($mode, $day)` (mode `whereHas` + weekday `whereHas`, eager-load
   modes, order by name) → JSON `data`.
5. `DriverList` renders one row per driver. `status: ready`.
6. No driver matches mode **and** weekday → `data: []` → "No one available for this
   delivery." (reused 006 empty state).
7. User edits the date → `onDateChange` updates `tourDate`; the weekday label
   recomputes; `use-tour-drivers` re-fetches for the new date; the list refreshes with
   no stale rows (spec 010 SC-002, spec 011 US2/US3).

## API contract

`GET /api/tour/drivers?mode=<trucking|driving|walking>&date=<YYYY-MM-DD>` (auth, `throttle:tour-read`)

- **200**: `{ "data": [ { "id": int, "name": string, "image_url": string|null,
  "modes": string[] }, … ] }` — drivers whose modes include `mode` **and** whose
  schedule includes the weekday of `date`, ordered by `name`; `data: []` when none.
- **422**: `mode` or `date` missing/invalid.
- **401**: unauthenticated.

See `contracts/driver-availability.md`.

## Design Artifacts (this run)

- `research.md` — weekday storage (lookup+pivot vs bitmask), 7-day seed resolution,
  server-side weekday deduction, timezone-safe front label, date param design.
- `data-model.md` — `week_days`, `driver_week_day`, `Driver.weekDays`, the extended
  `available` scope, the `WeekDay` enum, seed/fixture data.
- `contracts/driver-availability.md` — the updated `GET /api/tour/drivers` contract
  (adds `date`, weekday filtering) + the `TourDateField` UI contract.
- `quickstart.md` — manual verification (seed schedules, optimize, see default-today
  date + weekday label, change to a weekend/weekday, list refreshes / empties).

---

Generated by speckit.plan on 2026-07-01
