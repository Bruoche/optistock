# Research: Driver Schedule Filtering & Selected-Weekday Label

## R1 — How to store a driver's weekly schedule

**Decision**: A shared `week_days` lookup table (`id`, unique `label`) + a
`driver_week_day` pivot (`driver_id`, `week_day_id`, unique composite), with
`Driver belongsToMany(WeekDay)`. Identical shape to feature 006's
`delivery_modes` / `driver_delivery_mode`.

**Rationale**: The user explicitly asked for "an enum table with the days … and a
many-to-many relationship between drivers and days". This mirrors the mode design
already in the codebase, so the two schedule-like relations read the same way
(constitution II/III — consistency, low cognitive load). Querying "drivers who work
day X" is a single indexed `whereHas`.

**Alternatives considered**:
- *Bitmask / integer column on `drivers`* (7 bits) — compact but opaque, needs
  bit-twiddling to query, inconsistent with the modes relation, and can't be joined.
  Rejected.
- *CSV / JSON column of day names* — not relational, no referential integrity,
  awkward to filter server-side. Rejected.

## R2 — Which days go in the table (5 vs 7)

**Decision**: Seed all seven days, Monday–Sunday.

**Rationale**: The plan input said "monday to friday", but the approved spec 010
lists "week-end only", "a 4 day week", and "any other combination of days" as valid
schedules — weekend work must be representable. Confirmed with the user: **all seven
days**. The "monday to friday" phrasing is read as an example schedule, not the
table's contents.

**Alternatives considered**: Mon–Fri only (5 rows) — makes weekend-only drivers
impossible, contradicting spec 010. Rejected per user confirmation.

## R3 — Where the weekday is computed (front vs back)

**Decision**: The **back end** deduces the weekday from the request's `date` and is
the sole authority for filtering. The front end computes the weekday **only** to
render the label text.

**Rationale**: User requirement. Two reasons: (a) it keeps the door open for further
date-based filters (e.g. driver paid-time-off) that must run server-side against real
data; (b) resilience — if the front's weekday math is ever wrong, it can only
mislabel the UI, never change which drivers the back end returns. The server uses
`Carbon::dayOfWeekIso` (1 = Monday … 7 = Sunday), which is locale- and
timezone-independent, mapped to the `WeekDay` enum.

**Alternatives considered**: Trust a `day`/`weekday` param sent by the front —
rejected: pushes correctness-critical logic to the client and blocks future
server-side date filters.

## R4 — API surface for the date

**Decision**: Extend `GET /api/tour/drivers` with a **required** `date` query param
(`YYYY-MM-DD`), validated `required|date` in `AvailableDriversRequest`. The response
shape is unchanged from 006.

**Rationale**: The endpoint already exists and already takes `mode`; adding one more
validated query param is the smallest change and matches the existing `fetch` client.
The `date` (not a weekday) is sent so the server owns the deduction (R3). Missing or
malformed `date` → 422 (constitution IV — surfaced, not silent).

**Alternatives considered**: A separate endpoint, or a POST body — unnecessary; the
request is a simple authenticated read.

## R5 — Combined filter semantics

**Decision**: A driver is available iff their modes include the tour's `mode` **and**
their schedule includes the weekday of `date` (logical AND), expressed as two
`whereHas` clauses in the existing `available` scope.

**Rationale**: Spec 010 states both conditions must hold; AND is the only reading
consistent with "only drivers authorized to work on the selected day" layered on the
existing mode filter.

## R6 — Empty schedule

**Decision**: A driver with no linked days is valid and simply never appears (for any
date). No DB constraint enforces a minimum.

**Rationale**: Spec 010 edge case ("schedule is empty → never appears"). A pivot
cannot express "≥1 of 7" anyway; the factory attaches ≥1 day for useful fixtures, and
the empty case is covered by a test.

## R7 — Front-end date state & timezone safety

**Decision**: The presentation-phase date is a `YYYY-MM-DD` string held in
`optimize.tsx` (default = local today), persisted across "New tour" like `mode`/`loop`.
The weekday **label** is derived by parsing that string as a **local** calendar date
(construct at local noon) and formatting with `toLocaleDateString(locale, { weekday:
'long' })`.

**Rationale**: Parsing `new Date('2026-07-04')` treats the string as UTC midnight,
which can roll to the previous day in negative-offset zones and mislabel the weekday.
Constructing the date at local noon avoids the rollover so the label's weekday matches
the server's `dayOfWeekIso` for the same calendar date (spec 011 SC-004 — the two must
agree). Default-today and reset-persistence mirror the existing `mode`/`loop` handling.

**Alternatives considered**: A `Date` object in state — rejected; a plain ISO date
string is simplest to send as a query param and to compare, and sidesteps mutable Date
pitfalls. Naive UTC parsing — rejected for the rollover bug above.

## R8 — Weekday enum backing values

**Decision**: `App\Enums\WeekDay` with seven string-backed cases `monday`…`sunday`;
`week_days.label` mirrors those exactly (parity guarded by a unit test, like 006's
CR-2). A `WeekDay::fromDate(CarbonInterface): self` maps `dayOfWeekIso` → case.

**Rationale**: String labels keep the table human-readable and consistent with
`delivery_modes.label`; the ISO-number mapping keeps date→day deduction deterministic
and locale-independent. The enum owns the set; the model is the lookup row (006's D3
pattern), enum imported aliased as `WeekDayEnum` where both appear.
