# Contract: Driver page + day data

## `GET /driver/{driver}` (web, Inertia)

Auth: `auth`, `verified`. Renders `driver/manage`.

- Foreign/unknown driver → 404 (never confirm a foreign id exists; same posture as `TourPageController::edit`). *(Drivers are shared, not user-scoped — see spec assumptions; 404 is for a non-existent id.)*
- Query `?date=YYYY-MM-DD` optional; defaults to today (server date). Passed to the page as `initialDate`.
- Props:
  ```
  { driverId: number,
    initialDate: string,            // YYYY-MM-DD
    warehouses: [{ id, name }] }    // for the warehouse selector (dynamic DB data)
  ```
- **No `modes` prop.** The `DeliveryMode` enum carries no labels; the mode list + labels come from the frontend `DELIVERY_MODES` constant (`types/tour.ts`), which the identity/mode selectors already use.
- The heavy day data is NOT in props — the page fetches it client-side (below) so day-switching needs no Inertia visit.

## `GET /api/driver/{driver}/day?date=YYYY-MM-DD` (api, JSON)

Auth: `auth`. Throttle: `tour-read` (synchronous read, mirrors `tour/drivers`).

- `date` required, `Y-m-d`. Unknown driver → 404.
- 200 → `{ data: DriverDayData }` (shape in data-model.md). Empty day → `tours: []`, `legs: []`, `workday` all zero, `incomplete:false`.
- Routing failures degrade inside the payload (null durations, straight-line `path`, `incomplete:true`) — the endpoint itself still returns 200. Failures logged by `TravelTimeService`.
- Reuses `TravelTimeService::preload` to batch every connection of the day once.

### Guarantees
- `tours` ordered by `sequence` asc; each tour's `stops[].index` is 1..N in driven order (rotated/reversed to match the stored entry, same logic as `WorkdayLegsBuilder::stopsInDrivenOrder`).
- The k-th `tour`-kind leg ↔ `tours[k]` (client highlight mapping).
- `mode` is the day's single mode (FR-045), derived from its tours (first tour's mode); `null` for an empty day. Used identically for legs, totals, and reorder recompute. The single-mode invariant is enforced upstream by the FR-046 available-drivers filter.
