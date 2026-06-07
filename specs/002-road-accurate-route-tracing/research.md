# Research: Road-Accurate Route Tracing

**Date**: 2026-06-07 | **Scope**: resolve the `/route` unknowns flagged in plan.md before mapping code.

## R1. `polyline` encoding — RESOLVED (with one soft assumption)

- **Decision**: The `polyline` string is a **Google Encoded Polyline** (per the API docs: "defined by
  Google and taken up by several cartographic projects… an efficient way to compress a long list of
  coordinates into a shorter string"). Decode it to `[[lat,lng],…]` server-side
  (`PolylineDecoder`), no front-end decode lib (Decision D1).
- **Soft assumption**: **precision = 5** (Google's default; the docs don't state precision). If decoded
  points come out ~10× off / wrong scale on the first real leg, switch the decoder to **precision 6**.
  This is the single thing to eyeball on the first live render.
- **Test vector**: Google's canonical example `` _p~iF~ps|U_ulLnnqC_mqNvxq`@ `` (precision 5) decodes to
  `[[38.5,-120.2],[40.7,-120.95],[43.252,-126.453]]` — use it in `PolylineDecoderTest`.
- **Alternatives considered**: front-end decode via `@mapbox/polyline` — rejected (adds a front dep;
  `RouteLayer` already takes coordinates, so backend decode keeps the 001 FR-019 boundary clean).

## R2. `status` codes — RESOLVED

- **Decision**: Success is **`status == 0` or `status == "OK"`**; any other value is a failure. The docs
  say "the standard status code must be OK; for any other value the query will fail," and the example
  payload shows `status: 0` → treat `0`/`"OK"` as success defensively (the field may be numeric or the
  named string).
- **Known failure codes** (map to the leg error message): `SYNTAX_ERROR` (request incomplete/incorrect),
  `LIMIT_REACHED` (quota exhausted), `WRONG_KEY` (bad auth key), `REQUEST_DENIED` (cannot respond).
- A non-success `status`, a non-2xx HTTP, or a timeout → typed leg failure (logged, `ok:false`).

## R3. Units — RESOLVED (one soft assumption)

- **Decision**: `total_distance` is in **metres** (confirmed). Matches 001's `total_distance_m`.
- **Soft assumption**: `total_time` is in **seconds** (not stated; consistent with 001's
  `total_duration_s` and the m/s convention). Sanity-check the magnitude on the first real result
  (a Paris→Lyon leg should be a few hours, ~10⁴ s — not 10⁷ ms).

## R4. Per-call shape — RESOLVED

- **Decision**: `/route` is **point-to-point** (`origin` + `destination` only; no waypoints shown) →
  one call per consecutive leg. A closed N-stop tour = N legs (incl. last→first). Acceptable: the call
  is fast and synchronous (no queue), aggregated server-side into one response to the front (D3).
- **Mode**: send the same `mode` used for optimization (001 = `driving`); centralise so both stay
  congruent.

## R5. Synchronicity — RESOLVED

- **Decision**: Treat `/route` as a normal synchronous HTTP call (fast). **No queue/job/WebSocket.**
  The front calls one backend endpoint after the result is shown; the backend loops the legs and
  returns once. Short timeout so a dead host fails fast and falls back to straight lines.

## Open items carried to implementation

- Precision 5 vs 6 (R1) and `total_time` unit (R3) are **soft assumptions** — verify on the first live
  render/result, not blocking. Everything else is confirmed.
