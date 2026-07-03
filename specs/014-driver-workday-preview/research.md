# Research — Driver Workday Preview (014)

## Existing slice this feature builds on

- **Drivers endpoint (013)** — `GET /api/tour/drivers?mode&date&tour` already computes, per
  driver, the full chained workday: prior `TourSegment`s (from `driver_tour` pivot geometry,
  ordered by `sequence`), the selected candidate start (`TourStartSelector`), and the day total
  (`WorkdayEstimator`). Every **connection** (warehouse→start, end→next start, end→warehouse)
  is fetched through `TravelTimeService` — deduplicated, capped `Http::pool` — whose responses
  come from the same OpenStreet `/route` endpoint that returns an **encoded polyline**.
  `OpenStreetRouteClient::durationFromResponse` currently keeps only `total_time` and
  **discards the polyline**.
- **Trace endpoint (002)** — `POST /api/tour/geometry` accepts 2–10 `[lat,lng]` stops +
  `mode` + `loop`, returns per-leg decoded geometry `{legs: [{ok, coordinates, …}], …}`.
  `tour_id` is optional; when omitted there is **no persistence side effect**.
- **Map rendering (002)** — `RouteLayer` draws one GeoJSON LineString in the `--primary` role
  color resolved at runtime (MapLibre paint can't read CSS classes). `useTourGeometry` is the
  proven straight-line-first / road-path-later pattern with a token guard against stale
  responses (its FR-010).
- **Presentation UI (012/013)** — `ResultSummary` header holds the "New tour" `ActionButton`;
  `DriverList` rows currently open `AssignDriverDialog` directly on click.

## Decisions

### R1 — Legs ride the existing drivers payload; connection geometry comes free

**Decision**: extend the `GET /api/tour/drivers` response so each driver also carries `legs`
— the ordered black path pieces of their projected workday. Connection legs include their
**decoded polyline**, captured from the very `/route` responses `TravelTimeService` already
fetches for the duration math (today the polyline is parsed out and thrown away).

**Rationale**: zero additional routing calls, so the driver list gets no slower (hard user
constraint); the data is already in memory when the response is built.

**Alternatives**: a separate `GET /api/tour/drivers/{id}/legs` fetched on selection —
rejected: adds a round trip on every driver click (the exact interaction that must be
instant), re-fetches legs the first request already paid for, and multiplies race surface.

### R2 — One uniform leg shape

**Decision**: every leg is
`{ kind: 'connection'|'tour', dotted: bool, path: [[lat,lng],…], geometry: [[lat,lng],…]|null, loop: bool }`.

- `path` — the always-available straight fallback: `[from, to]` for a connection, the ordered
  (rotated) stop coordinates for a tour leg. Drawn immediately.
- `geometry` — decoded road coordinates when known (connections), else `null` (prior tours).
- `dotted` — explicit render flag (user requirement): `true` for connections, `false` for tours.
- `loop` — `true` only for a looping tour leg; it is the flag the front passes to the trace
  request so the return arc is traced.

**Rationale**: the front renders every leg identically (`geometry ?? path`, dash by `dotted`)
and has exactly what the trace request needs (`stops = path`, `loop`, current `mode`) with no
special cases. Coordinates are decoded `[lat,lng]` pairs to match the 002 `LegGeometry` shape
already consumed by the map code.

**Alternatives**: sending the encoded polyline string — rejected: the decoder lives server-side
(`PolylineDecoder`) and 002 already standardized decoded pairs at the API boundary.

### R3 — Prior tour legs ship `geometry: null` + a rotated stop path

**Decision**: prior assigned tours are **not traced server-side**. Their leg carries the
tour's ordered stop coordinates as `path`, **rotated to the recorded start/end**: a looping
tour entered at stop *k* → path starts at *k* and wraps (`k, k+1, …, k-1`) with `loop: true`;
a one-way tour → stops in position order, **reversed** when the pivot start is the last stop,
`loop: false`. The front draws the straight path immediately and calls the 002 trace endpoint
only when that driver is actually selected.

**Rationale**: tracing prior tours in the drivers request would add up to
`drivers × priorTours × stops` route calls to a request that must not get slower; lazy
per-selection tracing bounds the cost to the driver being looked at. Rotation happens
server-side because only the server has the pivot start/end + stop positions together; the
front stays dumb.

**Edge**: the pivot start coordinate is matched to a stop via the same rounded-key comparison
`TravelTimeService` uses (`Coordinate::isSameAs`); if no stop matches (data drift), the leg
falls back to the unrotated position order and the mismatch is logged as a `warning`
(constitution IV) — the preview still renders.

### R4 — The candidate tour is not in `legs`

**Decision**: `legs` contains only the black set — connections (including into the candidate's
selected start and out of its end) and prior tours. The candidate tour itself keeps its
existing rendering: the 002 `RouteLayer` in the `--primary` role color.

**Rationale**: the front already has the candidate's road geometry (`useTourGeometry`);
including it would double-draw and desynchronize two copies of the same path. The spec's color
rule (candidate = highlight, rest = neutral) falls out structurally: everything in `legs` is
neutral, the candidate layer is primary.

### R5 — `WorkdayLegsBuilder` assembles the legs server-side

**Decision**: a new single-purpose service `WorkdayLegsBuilder` turns
(warehouse, prior assignments with their stops, candidate start/end) into the ordered legs
array, pulling connection geometry from `TravelTimeService`. `DriverController` stays thin.

**Naming note**: the 013 travel layer keeps saying **connection**; "leg" here names a
**drawable path piece** — the same sense feature 002 established (`LegGeometry`). A
connection leg is a connection's drawable geometry; the terms compose rather than collide.

### R6 — `TravelTimeService` caches the whole leg, not just the duration

**Decision**: the internal map becomes `connectionKey → {durationS: ?int, coordinates: ?array}`.
`durationBetween()` behavior is unchanged; a new `geometryBetween()` exposes the decoded
coordinates (null when unroutable or coincident-points-zero). `OpenStreetRouteClient` gains a
non-throwing `legFromResponse(Response): ?array` (sharing the parsing of `mapToLeg`, minus the
throw) used by the pooled path; `durationFromResponse` folds into it.

**Rationale**: one parse per response, shared between the single-call and pooled paths — the
same "no duplicated request/response logic" rule the 013 refactor (its N2) established.

### R7 — Selection state lives in the optimize page

**Decision**: `selectedDriver` moves up to `pages/tour/optimize.tsx`. Three siblings need it:
the map overlay (new `WorkdayLayer` inside `TourMap`), the new "Assign Driver" button in
`ResultSummary`'s header, and the row highlight in `DriverList`. Clicking a row toggles
selection (re-click deselects); the dialog is opened only by the button. Selection clears on
reset, on date change, and whenever the driver list reloads (mode/date/tour change → the
fetched `Driver` objects are replaced, so stale selections cannot survive).

**Alternatives**: React context — rejected: three props across two levels doesn't justify it
(constitution III).

### R8 — Race safety: token-guarded lazy tracing with a per-load cache

**Decision**: a new `useWorkdayPreview(selectedDriver, mode)` hook

1. returns the selected driver's legs immediately (straight fallbacks render in the same
   frame — SC-001);
2. fires a trace request (`POST /api/tour/geometry` — `stops: leg.path`, `loop: leg.loop`,
   `mode`, **no `tour_id`**) for each leg with `geometry: null`;
3. applies a result only if that driver is **still the selected one** (identity check, the
   proven `useTourGeometry` token pattern) — a late response for a deselected driver is
   dropped, never drawn (spec FR-009);
4. keeps fetched geometry in a ref cache keyed by `driver.id + leg index`, cleared when the
   driver list reloads — re-selecting a driver reuses paths instead of refetching
   (spec FR-010), and cycling quickly never blocks: fetches are fire-and-forget, selection
   switches synchronously.

**Why no `tour_id`**: with it, the trace endpoint persists road totals onto that tour
(012 side effect); a preview must never rewrite an assigned tour's stored totals (R10 below
folded here). A failed trace keeps the straight line and logs via the endpoint's own
server-side logging; the hook keeps the fallback silently like `useTourGeometry` does.

**Alternatives**: prefetching traces for every listed driver — rejected: floods the routing
API for drivers never inspected (constitution V); AbortController on switch — unnecessary
on top of the identity guard, and letting the response land warms the cache for a re-select.

### R9 — Neutral color is a new map-role variable; dotted = MapLibre dasharray

**Decision**: the black set is drawn via a new role-named palette variable `--route-neutral`
(defined once in `app.css` for both themes; the map tiles stay light in dark mode, so the
value stays a dark neutral in both — matching how `--primary` keeps working on the map in
dark mode). `WorkdayLayer` resolves it at runtime exactly like `RouteLayer.primaryColor()`.
Dotted rendering = `line-dasharray` on the connection layer; solid for tour legs. Neutral
layers mount **before** the candidate `RouteLayer` so the highlight stays on top.

**Rationale**: constitution VI forbids raw hex at the point of use; `--foreground` flips to
white in dark mode and would vanish on the always-light raster tiles, so a dedicated
map-path role is the single-point-of-change answer. Precedent: `--text-on-color` was likewise
added as a role beyond the base five.

### R10 — Contract evolution, not a new endpoint

**Decision**: `GET /api/tour/drivers` response is extended in place (`legs` added per driver);
`POST /api/tour/geometry` is reused unmodified for preview traces. No new routes, no request
shape changes, no migrations.

**Rationale**: both endpoints already carry the needed inputs/outputs; the only backend gap
was throwing the polyline away.
