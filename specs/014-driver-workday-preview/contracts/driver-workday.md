# Contract — Available drivers with workday legs (v3)

`GET /api/tour/drivers?mode={mode}&date={YYYY-MM-DD}&tour={tourId}`

Supersedes the 013 version of this contract by **adding** the `legs` array per driver.
Everything else (auth, validation, 404 on foreign/unknown tour, dedup + capped-pool routing,
`projected_seconds` / `projected_incomplete` / `start_index` semantics) is unchanged.

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
                    "loop": false
                },
                {
                    "kind": "tour",
                    "dotted": false,
                    "path": [[48.87, 2.35], [48.86, 2.37], [48.85, 2.33]],
                    "geometry": null,
                    "loop": true
                },
                {
                    "kind": "connection",
                    "dotted": true,
                    "path": [[48.87, 2.35], [48.84, 2.32]],
                    "geometry": [[48.87, 2.35], [48.852, 2.338], [48.84, 2.32]],
                    "loop": false
                },
                {
                    "kind": "connection",
                    "dotted": true,
                    "path": [[48.82, 2.36], [48.93, 2.29]],
                    "geometry": null,
                    "loop": false
                }
            ]
        }
    ]
}
```

## `legs` semantics

- **Order** = the driver's chain order: `connection(warehouse → first tour start)`, then per
  prior tour `tour` leg followed by the `connection` out of it, ending with
  `connection(prior end → candidate start)` and `connection(candidate end → warehouse)`.
  The **candidate tour itself is absent** — the client already renders it (002 geometry,
  highlight color); its slot is between the last two connections.
  A driver with no prior tours has exactly two connection legs.
- **`path`** — always present, ≥ 2 `[lat, lng]` points; the straight-line fallback the client
  draws immediately. For `tour` legs it is the tour's ordered stop coordinates already
  rotated/reversed to the recorded start/end.
- **`geometry`** — decoded road coordinates when the server already holds them (connections,
  captured from the same `/route` responses that fed the duration math — no extra routing
  calls). `null` means the client may trace lazily (see below). An unroutable connection is
  also `null` (straight line stays; figure already flagged via `projected_incomplete`).
  A coincident-endpoints connection (genuine zero) is `null` with `path: [p, p]` — nothing
  to draw.
- **`dotted`** — render hint: `true` = connection (dashed), `false` = tour (solid). All legs
  render in the neutral route color; only the candidate tour uses the highlight color.
- **`loop`** — `true` only on a looping `tour` leg; pass it as the trace request's `loop`.

## Lazy tracing of a `geometry: null` tour leg

`POST /api/tour/geometry` (002 contract, unchanged):

```json
{ "stops": <leg.path>, "mode": "<tour mode>", "loop": <leg.loop> }
```

- **Never send `tour_id`** from the preview — with it the endpoint finalizes road totals onto
  that tour (012 side effect); the preview must not rewrite persisted totals.
- Response legs concatenate into the leg's road path exactly as `useTourGeometry` composes
  them; an `ok: false` response leg keeps that hop straight.
- The client only traces legs of the **currently selected** driver, and drops any response
  arriving after the selection changed.

## Client obligations (races — spec FR-009/FR-010)

- A response for driver A applied only while A is still selected.
- Fetched geometry cached per driver for the lifetime of the loaded driver list; re-selecting
  must not refetch, and switching selection must never wait on an in-flight trace.
