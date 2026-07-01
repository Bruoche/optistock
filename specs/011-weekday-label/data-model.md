# Data Model: Driver Schedule Filtering & Selected-Weekday Label

Builds on feature 006's `drivers` / `delivery_modes` / `driver_delivery_mode`
(unchanged). Adds a weekday lookup + a driver-schedule pivot, and extends the
`Driver` model.

## New tables

### `week_days` (shared lookup / "enum table")

| Column | Type             | Notes                                                      |
| ------ | ---------------- | ---------------------------------------------------------- |
| id     | bigint, PK, auto |                                                            |
| label  | string, unique   | One of `monday`…`sunday` (the `App\Enums\WeekDay` values). |

- Model `App\Models\WeekDay` (the `App\Enums\WeekDay` enum imported aliased as
  `WeekDayEnum` where both are referenced).
- Seeded by `WeekDaySeeder` with exactly the seven `App\Enums\WeekDay` backing values
  (idempotent `updateOrCreate` on `label`).
- Relation: `drivers(): belongsToMany(Driver::class, 'driver_week_day')`.
- No timestamps (static reference data); `$timestamps = false`.

### `driver_week_day` (pivot — the driver's schedule)

| Column       | Type                          | Notes                       |
| ------------ | ----------------------------- | --------------------------- |
| id           | bigint, PK, auto              |                             |
| driver_id    | FK → drivers.id, cascadeOnDelete   |                        |
| week_day_id  | FK → week_days.id, cascadeOnDelete |                        |

- Unique composite `(driver_id, week_day_id)` — a driver links a day at most once.
- The set of linked days IS the driver's schedule; an empty set = works no day.

## Changed model — `App\Models\Driver`

- Add relation `weekDays(): belongsToMany(WeekDay::class, 'driver_week_day')`.
- Extend the scope:
  `scopeAvailable(Builder $query, DeliveryModeEnum $mode, WeekDayEnum $day)` —
  `whereHas('deliveryModes', label = $mode->value)`
  **and** `whereHas('weekDays', label = $day->value)`,
  eager-loads `deliveryModes`, ordered by `name` asc.

## New enum — `App\Enums\WeekDay`

```php
enum WeekDay: string
{
    case Monday = 'monday';
    case Tuesday = 'tuesday';
    case Wednesday = 'wednesday';
    case Thursday = 'thursday';
    case Friday = 'friday';
    case Saturday = 'saturday';
    case Sunday = 'sunday';

    // 1 = Monday … 7 = Sunday (locale/timezone-independent).
    public static function fromDate(CarbonInterface $date): self;
}
```

- `fromDate` uses `$date->dayOfWeekIso` to select the case — the single place the
  server turns a date into a weekday (extensible for future date-based filters).

## Constraints / business rules

- **CR-1 (weekday parity)**: `week_days.label` values MUST equal the
  `App\Enums\WeekDay` backing values, so the deduced weekday matches a row. Unit test
  guards label ↔ enum parity (mirrors 006's CR-2).
- **CR-2 (combined availability)**: "available for a tour on a date" = the driver's
  `deliveryModes` includes the tour's mode **and** the driver's `weekDays` includes
  `WeekDay::fromDate(date)`.
- **CR-3 (empty schedule)**: a driver may have zero linked days; such a driver is
  never available for any date. Not enforced by a DB constraint.

## Entities (frontend)

The `Driver` type is unchanged (the schedule is a server-side filter, not surfaced in
the list payload). New client concept:

```ts
// The presentation-phase tour date is a plain ISO calendar date string.
type TourDate = string; // 'YYYY-MM-DD', defaults to local today

// Derived, read-only weekday label for TourDate (never sent to the server):
// formatWeekday('2026-07-04') -> 'Saturday'
```

## Relationships

```
Driver  *────*  DeliveryMode   (belongsToMany via driver_delivery_mode) — unchanged
Driver  *────*  WeekDay        (belongsToMany via driver_week_day)       — new (schedule)
Tour (transient, client-side) ── has mode + date ──
      filters Drivers by matching mode AND weekday(date)
```

No persisted Tour↔Driver link (assignment still out of scope).

## Seed / fixture data

- `WeekDaySeeder`: 7 rows — `monday`…`sunday` (idempotent).
- `DriverFactory`: after create, attaches a random **non-empty** subset of week days
  (1–7); `withDays(array $labels)` forces an exact schedule for deterministic tests.
- `DriverDemoSeeder`: each demo driver gets a schedule spanning the variety spec 010
  calls out — e.g. Mon–Fri, weekends only, a 4-day week, all-week — so the date filter
  visibly changes the list across days.
