# Data Model: Driver Management Page

No schema migration. This documents the entities the feature reads/writes and the view models it exposes.

## Persistent entities (all existing, unchanged)

### Driver (`drivers`)
- `id`, `name`, `image_path` (nullable), `warehouse_id` (FK, restrict-on-delete).
- Relations: `warehouse` (BelongsTo), `deliveryModes` (BelongsToMany via `driver_delivery_mode`), `weekDays` (BelongsToMany), `tours` (BelongsToMany via `driver_tour`, pivot `date`).
- Accessor: `image_url` (public URL or null).
- **Written by this feature**: `name`, `image_path`, `warehouse_id`, and the `driver_delivery_mode` pivot (`sync`). Weekday schedule is **not** edited here (spec assumption).

### Warehouse (`warehouses`)
- `id`, `name`, `latitude`, `longitude`; accessor `coordinate`.
- Read-only here; the full list is a page prop for the warehouse selector.

### DeliveryMode (`delivery_modes`)
- `id`, `label` (`trucking|driving|walking`). Full list is a page prop for the mode selector.

### Tour (`tours`) + Stop (`stops`)
- Read-only here. `Tour`: `loop`, `travel_duration_s` (nullable), `total_distance_m`; `total_duration_s` accessor. `Stop`: `position`, `latitude`, `longitude`, `duration_s`.

### Assignment (`driver_tour` pivot)
- `driver_id`, `tour_id` (unique — one driver per tour), `date`, `start_latitude/longitude`, `end_latitude/longitude`, `sequence`.
- **Read** for the day view (which tours, in what order, entered/left where).
- **Written** by reorder: `sequence` for all rows of the day, and `start_/end_lat/lng` when recomputed (normal save) — never `tour_id`/`driver_id`/`date`.

## Day mode (single per day)

A driver's day is single-mode (FR-045): every tour a driver has on a date shares one `delivery_mode`. The day's `mode` is **derived** from the day's tours — take the first tour's `delivery_mode->label`. No mode is stored on the `driver_tour` pivot. The invariant is enforced upstream at assignment time (see "Modified: available-drivers filter" below), so the tours are guaranteed to agree. If legacy data disagreed, the earliest tour's mode is used with no special handling (out of scope).

## Modified: available-drivers filter (existing flow)

`GET /api/tour/drivers` gains a date-aware, mode-only exclusion (FR-046): a driver with an existing assignment on the requested `date` whose tour mode ≠ the requested candidate mode is dropped from the result. Implemented in `DriverAvailabilityService::rowsFor` (which already holds `$date`) by chaining `->whereDoesntHave('tours', fn($q)=>$q->wherePivot('date',$date)->whereHas('deliveryMode', fn($m)=>$m->where('label','!=',$mode)))` onto the `Driver::available($mode,$weekday)` builder before `->get()`. The `Driver::available` **scope is left untouched** — it takes weekday (no date) and its 2-arg signature is pinned by `DriverTest` + the callsite; editing it would force a regression or a duplicate date-aware scope. No other facet of the response changes. Drivers with no same-date assignment, or same-mode assignments, are unaffected.

## Recompute rule (reorder)

Given the driver's warehouse `W`, the day `mode` (derived above), and the new ordered list of tours `[T1..Tn]`:

```
preload( [W→each candidate start, and the chained connections], mode )   # one batch, so measurement is a cache read
incoming = W
for each Ti in order:
    start_i = TourStartSelector.select(incoming, Ti, mode)   # nearest valid start candidate
    end_i   = Ti.endStopForStart(start_i)
    incoming = end_i.coordinate
# connections to verify: W→start_1, end_i→start_{i+1}, end_n→W
```

- **Failure detection is explicit.** `TourStartSelector::select` returns the first candidate when every duration is null, so it hides routing failure. The service therefore measures the chain's connections directly (via `TravelTimeService::durationBetween`, served from the preload) and checks for null itself.
- **Normal save** requires every measured connection to be non-null. Any null → **blocked (422)**, nothing persisted.
- **Force save** is **routing-free**: it does NOT call `TourStartSelector::select` (which would re-issue doomed API calls). Each tour's entry is the lowest-position start candidate, its exit deduced by `endStopForStart`; only `sequence` + those `start/end` persist. Logged at `warning`.
- **Invariants preserved**: stop set, per-stop durations, tour contents, and driver ownership are untouched (FR-035). One driver per tour (pivot uniqueness) unchanged.

## View models (wire shapes)

### `DriverDayData` — `GET /api/driver/{driver}/day` response `data`
```
{
  driver: {
    id, name, image_url: string|null,
    modes: DeliveryMode[],                 // driver's supported modes
    warehouse_id, warehouse_name,
    warehouse_coordinate: [lat, lng]
  },
  date: "YYYY-MM-DD",
  mode: DeliveryMode,                       // the day's single mode, derived from its tours (FR-045); null when the day is empty
  workday: {
    total_seconds: int|null,               // driven+stop+break; null-part → see incomplete
    driven_seconds: int|null,
    stop_seconds: int,
    break_seconds: int,
    incomplete: bool                        // true → total is a lower bound (show ≥ + warning)
  },
  tours: [                                  // running order (sequence asc)
    {
      id, sequence,                         // sequence = 0-based running position
      loop: bool,
      total_duration_s: int|null,           // travel + stops (null travel → null)
      driven_duration_s: int|null,          // tour travel_duration_s
      stop_seconds: int,
      start_coordinate: [lat, lng],         // entry point (T{n} marker sits here)
      stops: [ { index, lat, lng, duration_s } ]  // index 1..N in driven order
    }
  ],
  legs: WorkdayLeg[]                        // neutral drawable day pieces, chain order (see below)
}
```

### `legs` (reuses the existing `WorkdayLeg` shape verbatim)
- Chain order: `connection(W→T1.start)`, `tour(T1)`, `connection(T1.end→T2.start)`, `tour(T2)`, …, `tour(Tn)`, `connection(Tn.end→W)`.
- `kind`: `connection` (dotted) | `tour` (solid). `path`: straight fallback `[lat,lng][]`. `geometry`: null (client lazy-traces). `loop`: tour legs only. `highlight`: **always false on the wire** — the day view has no server-chosen highlight; the client sets emphasis from the selected tour index.
- Tour-leg ↔ tour mapping: the k-th `tour` leg corresponds to `tours[k]`, so selecting `tours[k]` highlights that tour leg + the connection legs immediately before and after it.

### Front-end view models (`resources/js/types/driver.ts`)
```
type DayStop  = { index: number; lat: number; lng: number; durationS: number };
type DayTour  = {
  id: number; sequence: number; loop: boolean;
  totalDurationS: number|null; drivenDurationS: number|null; stopSeconds: number;
  startCoordinate: [number, number]; stops: DayStop[];
};
type DayWorkday = {
  totalSeconds: number|null; drivenSeconds: number|null;
  stopSeconds: number; breakSeconds: number; incomplete: boolean;
};
type DriverDay = {
  driver: { id; name; imageUrl: string|null; modes: DeliveryMode[];
            warehouseId: number; warehouseName: string; warehouseCoordinate: [number,number] };
  date: string; mode: DeliveryMode;
  workday: DayWorkday; tours: DayTour[]; legs: WorkdayLeg[];   // WorkdayLeg imported from types/tour
};
```

### `UpdateDriverRequest` — `PATCH /api/driver/{driver}` (multipart)
- `name`: required, string, non-empty, max 255.
- `warehouse_id`: required, exists in `warehouses`.
- `modes[]` (or `mode_ids[]`): required, ≥1, each a valid delivery mode.
- `image`: optional, image file (mimetypes/size per app defaults), stored on `public` disk; absent = keep current.

### `ReorderToursRequest` — `POST /api/driver/{driver}/tour-order`
- `date`: required, date (`Y-m-d`).
- `tour_ids`: required array, ≥1, each an int; as a set MUST equal the driver's current assignment tour-ids for `date` (else 409).
- `force`: optional bool (default false).

## State & transitions (front-end)

- **Day fetch**: `idle → loading → (ready | error)`; a new date starts a fresh load and cancels the prior (stale responses discarded).
- **Tour selection**: `none ⇄ selected(tourId)`; re-clicking the selected clears it. At most one. Independent of fetch state (selection cleared on day change).
- **Identity edit**: `clean → dirty` on any field change; `dirty → saving → (clean | dirty)` (dirty retained on failure). Warehouse change adds a one-shot confirm gate before `saving`.
- **Reorder**: `pristine → reordered` on drag; `reordered → saving → (pristine | blocked | conflict | error)`. `blocked` reveals force-save (`→ saving(force) → pristine`). `conflict` refetches the day. A day change or Edit while `reordered`/`dirty` warns first (FR-041).
