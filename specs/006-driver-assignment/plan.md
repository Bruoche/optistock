# Implementation Plan: Delivery Driver Assignment

**Branch**: `006-driver-assignment` | **Date**: 2026-06-09 | **Spec**: [spec.md](spec.md)

## Summary

After a tour is optimized, the results page lists the **drivers able to run it** — those whose supported
delivery modes include the tour's mode — in the region the stop list occupied on the edit page. Each entry
leads with the driver's name and shows mode icons (walking figure / car / truck) for that driver's modes;
when none match, it shows the message **"No one available for this delivery."**. Scope is **listing only**
(no selecting/assigning, no time info).

Drivers and the mode lookup are persisted in the existing database. A new `GET /api/tour/drivers?mode=…`
endpoint returns the matching drivers, ordered alphabetically by name (the only ordering for now), each with
a public image URL and its list of mode labels. The frontend fetches this when a result is shown and renders
a new `DriverList` in place of the reserved slot in `ResultSummary`.

## Technical Context

**Stack**: Laravel 12 (PHP) backend + React 19 + Inertia + Tailwind v4 + shadcn/ui frontend.

**Storage**: existing app database (MySQL/SQLite per env). Driver images on the `public` filesystem disk,
exposed via `Storage::url()`.

**Testing**: PHPUnit (class-based, `Tests\TestCase` + `RefreshDatabase`) for backend; Vitest +
Testing Library for frontend.

**Request style**: the tour UI talks to `/api/*` JSON endpoints via `fetch` (not Inertia props) — see
`use-tour-optimization.ts`. The driver list follows the same pattern: a plain authenticated `GET`.

**Current state (touch points)**:
- `app/Enums/DeliveryMode.php` — string-backed PHP enum (`trucking`/`driving`/`walking`); the authoritative
  mode set for the optimize/geometry requests. Reused, unchanged.
- `routes/api.php` — `auth`-guarded tour endpoints; new driver route added here.
- `resources/js/components/tour/result-summary.tsx` — already reserves an empty `flex-1` slot "for the
  future drivers list"; this feature fills it.
- `resources/js/pages/tour/optimize.tsx` — holds the done-state `mode`; passes it to `ResultSummary`.
- `resources/js/types/tour.ts` — `DeliveryMode` type + `DELIVERY_MODES`; gains a `Driver` type.

**Project Type**: web app (Laravel + React SPA).

**Performance/Scale**: trivial — a single indexed lookup over a small drivers table; one `GET` per shown result.

## Constitution Check

*GATE: re-checked after design.*

- **I. Quality First / tests** — new endpoint, model relation, and `DriverList` covered: backend Feature test
  (auth, mode filtering, alphabetical order, payload shape, empty case) + frontend test (names, mode icons,
  empty message, ordering). PASS.
- **II/III. Readable & Simple** — one thin controller delegating to a query; idiomatic `belongsToMany`; one
  presentational `DriverList` component; reuse the existing `fetch` pattern. No new mechanism. PASS.
- **IV. Robustness** — the endpoint validates `mode` against the enum via a FormRequest (422 on bad input);
  the frontend fetch has explicit loading / empty / error states and never fails silently; a failed fetch is
  surfaced (toast / inline) and logged server-side on the failure path. PASS.
- **V. Performance with Clarity** — indexed FK lookup; no N+1 (eager-load `deliveryModes`). PASS.
- **VI. Consistent, Reusable Styling** — `DriverList` uses role-named color vars and shared primitives (mirrors
  `stop-list.tsx`); mode icons from the shared icon set; the empty message reuses the existing
  muted-foreground empty-state style. No raw hex. PASS.

No violations.

## Decisions

- **D1 — Three tables, many-to-many (confirmed with user).** `drivers`, a shared `delivery_modes` lookup
  (autoincrement `id` + unique string `label`), and a `driver_delivery_mode` pivot. A driver supports 1–2
  modes (never all three); modes are shared across drivers. This is the correct relational shape for "a shared
  enum table" + "a driver can have one or more modes" (the spec's "one-to-many" wording resolved to
  many-to-many). Laravel `belongsToMany`.

- **D2 — `delivery_modes.label` mirrors the enum values exactly.** Seeded with `trucking`, `driving`,
  `walking` (the `App\Enums\DeliveryMode` backing values) so the frontend filters/labels with the same
  strings it already uses (`DeliveryMode` TS type) — no translation layer. A `DeliveryModeSeeder` (called from
  `DatabaseSeeder`) inserts the three rows idempotently.

- **D3 — Eloquent model named `DeliveryMode`; alias the enum where both appear.** The lookup table *is* the
  canonical delivery-mode record, so its model gets the clean idiomatic name `App\Models\DeliveryMode`. The
  authoritative allowed-set stays the `App\Enums\DeliveryMode` enum; at the few sites that reference both
  (controller, request validation), import the enum aliased — `use App\Enums\DeliveryMode as DeliveryModeEnum`
  — keeping each symbol unambiguous (a "Model" suffix would be pure framework-layer noise, against the naming
  philosophy). `Driver` `belongsToMany(DeliveryMode::class)`.

- **D4 — `GET /api/tour/drivers?mode=<mode>`.** Auth-guarded (same `auth` group as the other tour routes),
  reusing the session cookie. Validates `mode` against the enum (FormRequest). Returns
  `{ "data": [ { id, name, image_url, modes: ["driving", …] }, … ] }`, drivers whose modes include `mode`,
  **ordered alphabetically by name**. A thin `DriverController@available` delegates to a query/`Driver` scope;
  no domain logic in the controller (mirrors `TourOptimizationController`). Throttled like the other tour
  endpoints.

- **D5 — Image via `public` disk + `image_url` accessor.** `drivers.image_path` (nullable) stores the disk
  path; the model exposes `image_url` through `Storage::disk('public')->url()`. When `image_path` is null the
  API returns `image_url: null` and the frontend shows a profile-icon placeholder (FR-008). Uploading/managing
  images through a UI is **out of scope** (drivers are seeded data) — "idiomatic image management" here means
  the standard public-disk + URL accessor, ready for an upload UI later.

- **D6 — Frontend: fetch on result, render `DriverList` in the reserved slot.** A small
  `use-tour-drivers.ts` hook fetches the endpoint for the done tour's `mode` and exposes
  `{ drivers, status }` (loading / ready / error). `ResultSummary` receives `mode` (from `optimize.tsx`'s
  done state) and renders `<DriverList … />` where the empty `flex-1` slot is today. Each row: image
  (placeholder fallback) + name (prominent) + mode icons beneath. Empty → "No one available for this
  delivery."; error → an inline retry/error line (logged), not a silent blank.

- **D7 — Mode icons.** `walking` → person/footprints icon, `driving` → car icon, `trucking` → truck icon
  (lucide-react, already the project's icon set). A single `MODE_ICON` map keyed by `DeliveryMode` keeps it
  reusable and consistent with `DELIVERY_MODES` ordering.

## Project Structure (feature-specific)

Backend — **new**:
- `database/migrations/<ts>_create_drivers_table.php` — `drivers`, `delivery_modes`, `driver_delivery_mode`.
- `database/seeders/DeliveryModeSeeder.php` — the three mode rows (idempotent); called from `DatabaseSeeder`.
- `database/factories/DriverFactory.php` — drivers with a name + optional image + attached modes (1–2).
- `app/Models/Driver.php` — `belongsToMany(DeliveryMode::class)`, `image_url` accessor, `available` scope.
- `app/Models/DeliveryMode.php` — the lookup row; `belongsToMany(Driver::class)`.
- `app/Http/Controllers/DriverController.php` — `available(AvailableDriversRequest)` → JSON.
- `app/Http/Requests/AvailableDriversRequest.php` — validates `mode` ∈ enum.

Backend — **change**:
- `routes/api.php` — add `GET tour/drivers` → `DriverController@available` in the `auth` group.
- `database/seeders/DatabaseSeeder.php` — call `DeliveryModeSeeder`.

Frontend — **new**:
- `resources/js/components/tour/driver-list.tsx` — the list + empty/error states + mode icons.
- `resources/js/hooks/use-tour-drivers.ts` — fetches `GET /api/tour/drivers?mode=`.

Frontend — **change**:
- `resources/js/types/tour.ts` — add `Driver` type (`id`, `name`, `imageUrl: string | null`, `modes: DeliveryMode[]`).
- `resources/js/components/tour/result-summary.tsx` — take `mode`, render `<DriverList />` in the reserved slot.
- `resources/js/pages/tour/optimize.tsx` — pass the done-state `mode` to `ResultSummary`.

Tests:
- `tests/Feature/DriverAvailabilityTest.php` — unauth rejected; only matching-mode drivers returned;
  alphabetical order; payload shape (`image_url`, `modes`); invalid mode → 422; empty result → `data: []`.
- `tests/Unit/DriverTest.php` — the `available` scope / mode relation; `image_url` accessor (path vs null).
- `resources/js/components/tour/driver-list.test.tsx` — renders names + correct mode icons; shows
  "No one available for this delivery." when empty; preserves alphabetical order; placeholder when no image.

Out of scope:
- Selecting/assigning a driver to a tour; driver CRUD/management UI; image upload UI; any time-related data.

## Flow

1. Tour reaches `done` → `optimize.tsx` renders `ResultSummary` with the tour's `mode`.
2. `use-tour-drivers` fetches `GET /api/tour/drivers?mode=<mode>` (`status: loading`).
3. Backend: `AvailableDriversRequest` validates `mode`; `DriverController@available` runs
   `Driver::available($mode)` (whereHas modes.label = mode, eager-load modes, order by name) → JSON `data`.
4. `DriverList` renders one row per driver (image/placeholder + name + mode icons). `status: ready`.
5. No matching driver → `data: []` → `DriverList` shows "No one available for this delivery.".
6. Fetch error → inline error line (logged server-side on the failure path); never a silent blank.
7. New tour / re-optimize with a different mode → re-fetch for the new mode; the list refreshes (FR-009).

## API contract

`GET /api/tour/drivers?mode=<trucking|driving|walking>` (auth, throttled)

- **200**: `{ "data": [ { "id": int, "name": string, "image_url": string|null, "modes": string[] }, … ] }`
  — drivers whose `modes` include `mode`, ordered by `name` ascending; `data: []` when none.
- **422**: `mode` missing/not in the enum.
- **401**: unauthenticated.

See `contracts/driver-availability.md`.

## Design Artifacts (this run)

- `research.md` — relationship modeling (many-to-many vs literal one-to-many), enum-vs-table naming, image handling, fetch pattern.
- `data-model.md` — `drivers`, `delivery_modes`, `driver_delivery_mode`; fields, constraints, relations.
- `contracts/driver-availability.md` — the `GET /api/tour/drivers` request/response contract + the `DriverList` UI contract.
- `quickstart.md` — manual verification (seed, optimize, observe list + empty case).

---

Generated by speckit.plan on 2026-06-09
