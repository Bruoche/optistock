# Data Model: Tour Driver Assignment (+ tour/stop persistence)

Adds persistence for tours and stops, and an assignment association to drivers.
Reuses the existing `delivery_modes` (006) and `drivers` (006) tables unchanged.

## New tables

### `tours`

| Column            | Type                     | Notes                                                   |
| ----------------- | ------------------------ | ------------------------------------------------------- |
| id                | bigint, PK, auto         |                                                         |
| user_id           | FK → users.id, cascade   | The planner who optimized it (ownership; future lists). |
| delivery_mode_id  | FK → delivery_modes.id   | The tour's travel mode (driving/walking/trucking).      |
| loop              | boolean                  | Closed loop (004) — kept for congruent re-tracing/edit. |
| travel_duration_s | unsigned int, nullable   | Road travel time; seeded from the TSP estimate, then    |
|                   |                          | updated to the road total by the geometry trace (R5).   |
| total_distance_m  | unsigned int, nullable   | Road distance (set with the duration by the trace).     |
| created_at/updated_at | timestamps           |                                                         |

- Model `App\Models\Tour`.
- Relations: `deliveryMode(): belongsTo(DeliveryMode)`, `user(): belongsTo(User)`,
  `stops(): hasMany(Stop)` ordered by `position`, `drivers(): belongsToMany(Driver, 'driver_tour')->withPivot('date')`,
  and a convenience `assignment(): hasOne(...)`/`driver()` (unique pivot → at most one).
- Accessor `total_duration_s`: **propagates the unknown state** — `travel_duration_s === null
  ? null : travel_duration_s + stops.sum('duration_s')`. Null travel (no routing call / API
  or trace failure) yields a null total, distinct from a genuine zero, so the frontend can
  detect the unknown case (spec FR-012, clarified 2026-07-01). **Do NOT coalesce to 0 here.**
  This is the figure that matches the presentation's "Tour duration".
  **Note the overload**: this accessor means travel + stops, whereas the optimize/geometry
  API payload key `total_duration_s` means travel-only (road). The DB column
  `travel_duration_s` is the unambiguous one — comment the accessor to prevent a mix-up.
- The `assigned_seconds` **aggregate** (committed load, below) is a separate computation and
  DOES tolerate unknown travel: it sums `COALESCE(travel_duration_s, 0) + Σ stop.duration_s`
  so a committed tour with unknown travel still contributes its stop time. Detection (null,
  per-tour, for the current tour) and committed-load summation (COALESCE, across tours) are
  intentionally distinct — the accessor never coalesces; the SQL aggregate does.

### `stops`

| Column      | Type                     | Notes                                          |
| ----------- | ------------------------ | ---------------------------------------------- |
| id          | bigint, PK, auto         |                                                |
| tour_id     | FK → tours.id, cascade   |                                                |
| latitude    | decimal(10,7)            | Stop coordinate.                               |
| longitude   | decimal(10,7)            | Stop coordinate.                               |
| duration_s  | unsigned int             | Per-stop delivery duration (007), seconds.     |
| position    | unsigned int             | Optimized visiting order, 0-based.             |
| created_at/updated_at | timestamps     |                                                |

- Model `App\Models\Stop`; `belongsTo(Tour)`.
- Unique `(tour_id, position)` keeps the order unambiguous and edit-friendly.

### `driver_tour` (assignment association)

| Column     | Type                        | Notes                                       |
| ---------- | --------------------------- | ------------------------------------------- |
| id         | bigint, PK, auto            |                                             |
| tour_id    | FK → tours.id, cascade, **unique** | One driver per tour (spec).          |
| driver_id  | FK → drivers.id, cascade    | The assigned driver.                        |
| date       | date                        | The day the tour is assigned for (011).     |
| created_at/updated_at | timestamps       |                                             |

- Pivot for `Driver belongsToMany Tour` (and the inverse). Unique `tour_id` = one
  assignment per tour; delete = un-assign, update `driver_id` = re-assign (future).
- Index `(driver_id, date)` — backs the projected-hours aggregate.

## Constraints / business rules

- **CR-1 (atomic persist)**: a `Tour` and its `Stop` rows are written in one
  transaction; never a tour without its stops. Each stop's `duration_s` is resolved from
  a `normalizedCoord → duration_s` map (not a TSP input index, which the normalize sort +
  cache-hit path sever); identical-coord stops are interchangeable for duration.
- **CR-2 (single done-persist)**: exactly one tour per optimization result — persisted
  inside `OptimizeTourJob` (once per `job_uuid`) or the synchronous cache-hit path, so
  the broadcast+poll dual-settle cannot duplicate it.
- **CR-3 (eligibility on assign)**: an assignment is accepted only if the requesting
  user **owns the tour** (`tour.user_id`, else `404`) AND the driver supports the tour's
  `delivery_mode` AND is scheduled on `date`'s weekday (006/011), all re-checked
  server-side.
- **CR-4 (one driver per tour)**: enforced by the unique `driver_tour.tour_id`;
  `updateOrCreate` on `tour_id` makes assign idempotent and re-assign a same-row update.
- **CR-5 (duration parity)**: `tours.total_duration_s` (travel + stops) is the figure
  used for projected hours and must equal the presentation's "Tour duration" once the
  geometry trace has set `travel_duration_s` (FR-007).
- **CR-6 (unknown travel is preserved)**: `travel_duration_s` is null when the road time
  is undetermined (no routing call / API or trace failure) — never coerced to 0. The
  `total_duration_s` accessor propagates that null so the unknown state is detectable
  end-to-end (FR-012); the field stays a plain writable nullable column so a future
  trusted manual-entry setter can populate it (FR-013).
- **CR-7 (persist failure is surfaced)**: a `Tour`/`Stop` write failure on either done-path
  (incl. an unmappable stop duration) rolls back atomically, is logged, and is surfaced to
  the user as `persist_failed` — never a silent unsaved route. An unsaved tour has no `id`
  and is never offered for assignment (FR-014 / research R10). A geometry-persist failure
  is a refinement of an already-saved tour and is logged-only, not surfaced (RB4).

## Derived / API-surfaced values

- **`assigned_seconds`** (per driver, for a queried date): `Σ (COALESCE(travel_duration_s,0)
  + Σ stop.duration_s)` over the driver's tours assigned for that date. Returned by
  `GET /api/tour/drivers`. Uses `COALESCE` (not the null-propagating accessor) so a
  committed tour with unknown travel still contributes its stop time and the sum stays
  numeric (see CR-6).
- **Projected hours** (per driver row, client): `assigned_seconds + currentTourTotalS`
  where `currentTourTotalS` is the on-screen tour total (road duration + wait).

## Entities (frontend)

```ts
// resources/js/types/tour.ts
type TourResult = {
    id: number;                 // NEW — the persisted tour id
    ordered_stops: OptimizedStop[];
    total_distance_m: number | null;
    total_duration_s: number | null;
};

type Driver = {
    id: number;
    name: string;
    imageUrl: string | null;
    modes: DeliveryMode[];
    assignedSeconds: number;    // NEW — committed load for the queried date
};
```

## Forward-compatibility (later features, not built now)

- **Un-assign / re-assign**: delete or update the single `driver_tour` row; nothing on
  `tours`/`stops` changes. The unique `tour_id` + `updateOrCreate` already support it.
- **Editing a route**: mutate/re-order `stops` (rows with `position`) and re-run the
  geometry trace to refresh `travel_duration_s`; `tours` row is stable.
- **Per-driver daily-hours limit**: a future `drivers` attribute compared against
  `assigned_seconds`; no schema change here forces or blocks it.

## Seed / fixture data

- `TourFactory` — a tour with a mode, loop, and a small ordered set of `Stop`s (via
  `StopFactory`), optional `travel_duration_s`.
- Assignments can be created by attaching a driver with a `date` pivot for tests of the
  projected-hours aggregate.
