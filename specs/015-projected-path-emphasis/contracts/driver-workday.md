# Contract — Available drivers with workday legs (v4)

`GET /api/tour/drivers?mode={mode}&date={YYYY-MM-DD}&tour={tourId}`

Supersedes the 014 v3 contract by **adding** a `highlight` boolean to each leg. Everything else
(auth, validation, 404 on foreign/unknown tour, dedup + capped-pool routing, `projected_seconds` /
`projected_incomplete` / `start_index` semantics, leg order, `path` / `geometry` / `dotted` /
`loop` semantics, lazy tracing) is unchanged from v3.

## Response `200`

```json
{
    "data": [
        {
            "id": 7,
            "name": "Nadia Benali",
            "image_url": null,
            "modes": ["Trucking"],
            "warehouse_name": "Gennevilliers",
            "projected_seconds": 14520,
            "projected_incomplete": false,
            "start_index": 2,
            "legs": [
                {
                    "kind": "connection",
                    "dotted": true,
                    "path": [[48.93, 2.29], [48.87, 2.35]],
                    "geometry": [[48.93, 2.29], [48.921, 2.301], [48.87, 2.35]],
                    "loop": false,
                    "highlight": false
                },
                {
                    "kind": "tour",
                    "dotted": false,
                    "path": [[48.87, 2.35], [48.86, 2.37], [48.85, 2.33]],
                    "geometry": null,
                    "loop": true,
                    "highlight": false
                },
                {
                    "kind": "connection",
                    "dotted": true,
                    "path": [[48.85, 2.33], [48.84, 2.32]],
                    "geometry": [[48.85, 2.33], [48.845, 2.325], [48.84, 2.32]],
                    "loop": false,
                    "highlight": true
                },
                {
                    "kind": "connection",
                    "dotted": true,
                    "path": [[48.82, 2.36], [48.93, 2.29]],
                    "geometry": null,
                    "loop": false,
                    "highlight": true
                }
            ]
        }
    ]
}
```

The two `highlight: true` legs are the connections bracketing the candidate tour: the drive into
the candidate's start (here, out of the last prior tour) and the drive out of the candidate's end
(here, back to the warehouse). The candidate tour itself sits between them and is not a leg — the
client draws it in the primary color already.

## `highlight` semantics

- **`highlight`** — `true` only on the two connection legs that bracket the candidate tour: the
  connection immediately **before** the (absent) candidate slot and the connection immediately
  **after** it. Every prior-tour connection and every `tour` leg is `false`.
- A driver with **no prior tours** has exactly two connection legs (warehouse → candidate,
  candidate → warehouse); both are candidate-adjacent, so **both are `highlight: true`**.
- `highlight` is independent of `geometry`: a highlighted leg may still have `geometry: null` and
  be traced lazily; its highlight state does not change across the trace.

## Client rendering (spec FR-001..007)

- `highlight: true` → draw in the **primary** role color at full opacity.
- `highlight: false` → draw in the **neutral** role color at `0.5` opacity.
- `dotted` still governs dash style independently of `highlight` (highlighted connections stay
  dotted).
- Color and opacity derive from `highlight` (a leg-role property), never from whether the leg is
  currently a straight-line placeholder or a traced road path.

## Lazy tracing

Unchanged from v3 — `POST /api/tour/geometry` with `{ stops: leg.path, mode, loop: leg.loop }`,
never a `tour_id`, applied only for the still-selected driver. `highlight` plays no part in
tracing; it is a render hint only.
