# Contract: Driver Availability (updated for schedule filtering)

Supersedes `specs/006-driver-assignment/contracts/driver-availability.md` for the
request: the endpoint now also requires a `date` and filters by the driver's weekly
schedule. Response shape is unchanged.

## HTTP — `GET /api/tour/drivers`

Auth: required (session cookie, `auth` group). Throttled (`throttle:tour-read`).

### Request

| Param | In    | Required | Value                                   |
| ----- | ----- | -------- | --------------------------------------- |
| mode  | query | yes      | `trucking` \| `driving` \| `walking`    |
| date  | query | yes      | calendar date `YYYY-MM-DD`              |

Validated by `AvailableDriversRequest`:
- `mode` ∈ `App\Enums\DeliveryMode`
- `date` → `required|date`

The server derives the weekday from `date` via
`App\Enums\WeekDay::fromDate($date)` (ISO `dayOfWeekIso`, 1 = Monday … 7 = Sunday).
**No weekday is accepted from the client.**

### Responses

**200 OK** — drivers whose modes include `mode` **and** whose schedule includes the
weekday of `date`, ordered by `name` ascending:

```json
{
  "data": [
    {
      "id": 12,
      "name": "Amélie Durand",
      "image_url": "http://localhost/storage/drivers/amelie.jpg",
      "modes": ["driving", "walking"]
    }
  ]
}
```

- `image_url`: `null` when the driver has no stored image (frontend placeholder).
- `modes`: each driver's full supported set (labels), not filtered to the query mode.
  (The schedule/weekdays are **not** included in the payload — filtering is
  server-side only.)
- Empty match → `{ "data": [] }` (200, not 404) — e.g. no driver both supports `mode`
  and works that weekday.

**422 Unprocessable** — `mode` or `date` missing/invalid:

```json
{ "message": "…", "errors": { "date": ["The date field is required."] } }
```

**401 Unauthorized** — not authenticated.

### Guarantees

- Only drivers supporting `mode` **and** scheduled for `weekday(date)` appear
  (spec 010 FR-004, SC-001).
- Deterministic alphabetical order by `name`.
- Weekday deduction is server-side and locale/timezone-independent (spec 010 FR-005).
- No N+1: `deliveryModes` eager-loaded.

## UI contract — `TourDateField` (presentation phase)

Rendered inside `ResultSummary`, above `DriverList`.

- A **date input** (`type=date`, shadcn input primitive), showing the current
  `tourDate` (default = local today; persists across "New tour").
- Beside it, a **small read-only text** naming the selected date's weekday
  (e.g. "Saturday"), derived client-side from the date (spec 011 FR-001/FR-002).
- The weekday label updates immediately when the date changes (spec 011 FR-003) and
  always shows a value (the date always has one — spec 011 FR-004).
- Changing the date lifts up via `onDateChange`, updating `tourDate`, which:
  1. re-renders the weekday label, and
  2. re-fetches `DriverList` for the new date (spec 010 US3 / SC-002 — no stale rows).
- Styling: shared shadcn input + role-named color vars; the label uses the
  muted-foreground text style. No raw hex (constitution VI).

## UI contract — `DriverList` (unchanged from 006, now date-driven)

Same rendering as 006 (name prominent, mode icons beneath, image/placeholder,
loading / error / empty states, empty message **"No one available for this
delivery."**). The only change: it takes `date` in addition to `mode` and passes both
to `use-tour-drivers`, so the shown set reflects the selected date's weekday.

---

> **Superseded/extended by feature 012**: `GET /api/tour/drivers` now also returns `assigned_seconds` per driver (committed load for the queried date), and the driver list became actionable via `POST /api/tour/{tour}/assign`. See `specs/012-tour-driver-assignment/contracts/`.
