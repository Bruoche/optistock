# Research: Warehouse & Origin Map Markers

Building on 013 (`incomingPoint`, warehouse origin), 014 (workday preview), 015 (`WorkdayLayer`),
016 (result-view selection). Only the reused slice + the four decisions are recorded.

## Reused slice (unchanged behavior)

- **`DriverController` row closure** already computes, per driver:
  `$warehouse = $driver->warehouse->coordinate` and `$incoming = $this->incomingPoint($driver,
  $workday['prior_tours'])`. `incomingPoint` returns the last prior tour's `end`, else the
  warehouse. Both are exactly the two marker locations — no new computation.
- **`Coordinate`** carries `lat`/`lng` and `isSameAs(other)` (rounded-key equality) — the test for
  "incoming point is the warehouse" (no prior tour).
- **`TourMap`** renders `<Map>` with numbered stop `<Marker>`s and passes `children` into the same
  map context; overlays (`WorkdayLayer`, `RouteLayer`) already ride there. The stop marker's shape
  is `flex size-6 items-center justify-center rounded-full … shadow`.
- **`--route-neutral` (#1a1a1a, identical in light and dark)** is the neutral near-black role
  `WorkdayLayer` already paints its non-highlight lines with — the on-palette black for the markers.

## Decisions

### D1 — Coordinates via two additive backend fields, not frontend leg-derivation

Both marker positions are derivable on the client from the already-sent `legs` (`legs[0].path[0]` is
the warehouse; the first `highlight` connection's `path[0]` is the incoming point). **Rejected.**
That reads meaning out of leg **ordering** — an implicit, uncontracted invariant of
`WorkdayLegsBuilder`; a future reorder would silently mis-place the markers with no type or test
signal, coupling the map to backend internals across the HTTP seam.

**Chosen**: serialize the two locals the controller already holds (`$warehouse`, `$incoming`) as
`warehouse_coordinate` / `previous_tour_end`. The "incoming = warehouse when no prior tour"
invariant stays in `incomingPoint` where it lives; the fields are self-documenting; it mirrors how
017 added `time_to_tour`/`time_from_tour` to this same closure. Cost is two scalar pairs on the
payload — no new routing, no new query.

*Alternatives*: (a) frontend leg-derivation — fragile ordering coupling (above); (b) a second
endpoint — needless round-trip for data already assembled here.

### D2 — `previous_tour_end` is null exactly when the driver departs from the warehouse

`warehouse_coordinate` is always `[lat,lng]`. `previous_tour_end` = `null` when
`$incoming->isSameAs($warehouse)` (no prior tour that day), else `[$incoming->lat, $incoming->lng]`.
Null **is** the "0"-marker gate (FR-003/FR-005) — decided server-side in one place rather than
re-inferred by comparing coordinates on the client. `Coordinate::isSameAs` (rounded key) avoids a
float-equality edge when a prior tour happens to end exactly at the warehouse.

### D3 — Marker styling: reuse the stop-marker circle, neutral fill at 50% opacity, near-white glyph

Markers reuse the numbered-stop circle utilities (`size-6 rounded-full flex items-center
justify-center shadow`, `anchor="bottom"`) so they match size/shape (FR-002/FR-004). The **fill**
uses the `--route-neutral` role at 50% opacity (`bg-route-neutral/50`) — the requested "black at
50%", on-palette, matching the workday lines' neutral. The 50% is on the fill only, not the wrapper,
so the glyph stays full-opacity and legible. The warehouse marker shows a lucide **`Building2`**
glyph ("building icon"); the other shows the text **"0"** (continuing the stop numbering as the
point before stop 1).

**Glyph color** needs a light role that reads on the dark neutral fill in **both** themes. No
existing role qualifies: `--foreground`/`--background` flip per theme, and `--text-on-color`
(#000/#111) is dark (it pairs with the *light* primary fill). The map raster tiles are
theme-independent and `--route-neutral` is deliberately theme-stable (see its app.css comment), so
the correct glyph is a **theme-stable near-white**. Constitution VI's sanctioned single-source
mechanism for a needed color is a palette variable, so add a companion role
**`--route-neutral-foreground: #ffffff`** (identical in `:root` and `.dark`), registered as
`--color-route-neutral-foreground`, mirroring the existing `--text-on-color` ↔ colored-fill
pairing. Glyph = `text-route-neutral-foreground`. No raw literal at the point of use; re-theming
stays a single-point edit.

*Alternatives*: (a) `text-white` at the point of use — rejected, raw off-palette literal (VI).
(b) Reuse `--text-on-color` — rejected, it is dark, unreadable on the neutral fill.
(c) An unfilled/outlined marker (glyph in `--route-neutral`) — rejected, the user asked for a filled
circle "like the numbered circles". (d) `Warehouse` lucide glyph — also fine; `Building2` matches
the user's "building icon" wording.

### D4 — Render as a child component of the map, gated on selection

New `WorkdayMarkers` component takes the selected `Driver` and renders the two `<Marker>`s; mounted
in `optimize.tsx` as `{isDone && selectedDriver && <WorkdayMarkers driver={selectedDriver} />}`,
a sibling of `WorkdayLayer`/`RouteLayer`. This keeps `TourMap` and the numbered stops untouched
(FR-007), ties the markers to the selection lifecycle (FR-006), and isolates the styling in one
small presentational unit.

*Alternatives*: rendering inside `TourMap` — would push driver/selection knowledge into the generic
map; passing coords as loose props instead of the `Driver` — more plumbing for no gain.
