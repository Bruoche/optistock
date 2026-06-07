# Feature Specification: Road-Accurate Route Tracing

**Feature Branch**: `002-road-accurate-route-tracing`

**Created**: 2026-06-07

**Status**: Draft

**Input**: User description: "Use Open Street's /route endpoint to trace the actual track of the circuit on the map instead of straight lines. When the result is obtained, the straight lines are drawn first as fallback, then a request is launched to get each actual polyline; the straight lines are replaced by the actual polyline. The time estimate initially obtained is also used as fallback and then replaced by /route's result — so if the initial optimization returns a null time estimate it is replaced by the one /route provides."

## Context

This feature builds on **001 — Delivery Route Optimization** (complete). 001 produces an optimized stop order and draws the tour as **straight lines** between stops, behind the isolated `RouteLayer` boundary (FR-019 of 001). For 2-point tours, 001 returns `null` distance/duration ("Unavailable") because the TSP API can't process two points. This feature replaces the straight lines with the **actual road path** and backfills the missing metrics, using OpenStreet's `/route` endpoint — as a **progressive enhancement** that never blocks or breaks the existing result.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See the real road path of the optimized tour (Priority: P1)

A delivery planner who just received an optimized tour sees the route immediately as straight lines, then watches it snap to the actual roads the driver would follow, without any extra action.

**Why this priority**: The straight-line tour is hard to read as a real itinerary; showing the true road path is the core value of this feature.

**Independent Test**: Optimize a tour of ≥3 stops; confirm straight lines appear first, then are replaced by a road-following path covering every leg in visit order (including the return-to-origin leg of the closed tour).

**Acceptance Scenarios**:

1. **Given** an optimized tour has just been displayed with straight lines, **when** the road geometry becomes available, **then** the straight lines are replaced by a path that follows roads for every leg, in the same visit order, with no flicker of an empty map.
2. **Given** the road geometry is still being retrieved, **when** the planner looks at the map, **then** the straight-line fallback remains visible so the tour is never blank while loading.

---

### User Story 2 - Get an accurate travel estimate, including for 2-point tours (Priority: P2)

A planner sees the optimizer's initial travel estimate immediately, then sees it refined to the road-accurate figure; for a 2-point tour (which had no estimate), the figure appears once the road path is computed.

**Why this priority**: Accurate distance/duration improves planning confidence and removes the "Unavailable" gap left by 001 for 2-point tours.

**Independent Test**: For a ≥3-stop tour, confirm the initial estimate shows first then updates to the road-accurate value; for a 2-point tour, confirm the estimate starts "Unavailable" then resolves to a real value.

**Acceptance Scenarios**:

1. **Given** an optimized tour with an initial duration estimate, **when** the road-accurate metrics arrive, **then** the displayed duration (and distance) are replaced by the road-accurate values.
2. **Given** a 2-point tour whose initial duration was "Unavailable", **when** the road-accurate metrics arrive, **then** the duration is replaced by the road-accurate value.

---

### User Story 3 - Graceful fallback when road tracing is unavailable (Priority: P3)

If the road geometry can't be retrieved (endpoint down, a leg fails), the planner still keeps a usable result: straight lines and the initial estimate remain, and the failure is logged.

**Why this priority**: The enhancement must never degrade the working 001 result; robustness is required by the constitution (no silent failure).

**Independent Test**: Force the route endpoint to fail; confirm straight lines + initial estimate persist, the user is not shown a broken/blank state, and the failure is recorded in the logs.

**Acceptance Scenarios**:

1. **Given** the route endpoint fails for the whole tour, **when** the planner views the result, **then** the straight-line tour and the initial estimate remain and a failure is logged.
2. **Given** the route endpoint fails for one leg only, **when** the geometry is applied, **then** the successful legs show road paths and the failed leg falls back to a straight line, with the failure logged.

---

### Edge Cases

- **2-point tour**: the route is a single leg out and back (closed tour). Road geometry both draws the path and supplies the previously-`null` distance/duration.
- **Endpoint slow/unreachable**: the straight-line fallback must remain; the enhancement must time out and fail safe, never hang the result view.
- **Partial leg failure**: some legs return geometry, others don't — successful legs upgrade, failed legs stay straight; aggregate metrics reflect what is known (see FR-008).
- **Stale result**: if the planner starts a new optimization while geometry for a previous one is still loading, the late geometry must not overwrite the new result.
- **Identical/zero-length leg**: consecutive duplicate coordinates must not break geometry retrieval (degenerate leg handled gracefully).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST, after an optimized tour is displayed, retrieve the actual road path for the tour using the OpenStreet `/route` endpoint, covering every consecutive leg in visit order including the closing leg back to the origin.
- **FR-002**: The system MUST display the straight-line tour immediately as a fallback and replace it with the road-following path once geometry is available — a progressive enhancement requiring no extra user action.
- **FR-003**: The system MUST use the optimizer's initial travel estimate as the displayed value first, then replace it with the road-accurate distance and duration once the `/route` results arrive.
- **FR-004**: When the initial estimate is absent (e.g. a 2-point tour, `null` metrics from 001), the system MUST populate the distance and duration from the `/route` results once available.
- **FR-005**: The system MUST keep the straight-line fallback and the initial estimate intact if road geometry cannot be retrieved (whole-tour failure), and MUST NOT present a blank or broken result.
- **FR-006**: On a per-leg basis, the system MUST upgrade legs whose geometry was retrieved and leave straight-line fallbacks for legs that failed.
- **FR-007**: The system MUST NOT block or delay the original optimization result on the road-tracing work; tracing happens after the result is shown.
- **FR-008**: When aggregate road-accurate metrics cannot be fully computed (one or more legs failed), the system MUST clearly represent the estimate's status rather than show a misleadingly precise total (e.g. keep the initial estimate, or mark the road-accurate total unavailable).
- **FR-009**: The system MUST log every road-tracing failure (whole-tour or per-leg) with enough context to diagnose it, per the project constitution (no silent failure).
- **FR-010**: The system MUST ignore late-arriving geometry for a tour that is no longer the one on screen (a newer optimization or a reset has occurred).
- **FR-011**: The routing API key MUST NOT be exposed to the browser; road-geometry retrieval MUST be performed server-side. *(Security constraint.)*

### Key Entities *(include if feature involves data)*

- **Route Leg**: an ordered pair of consecutive stops (origin → destination) in the optimized tour, including the closing leg (last → first).
- **Leg Geometry**: the road-following path for a single leg (an ordered list of coordinates) plus that leg's distance and duration.
- **Tour Geometry**: the ordered collection of leg geometries for the whole tour, with aggregated distance and duration, attached to the same `job_uuid`/tour as the 001 result.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: After a tour is optimized, the straight-line path is visible immediately (no perceptible wait) and is replaced by the road-following path once geometry is ready.
- **SC-002**: For at least 95% of successful optimizations with a reachable routing service, every leg (including the return leg) is shown as a road-following path.
- **SC-003**: A planner can read the road-accurate total duration for a completed tour, including 2-point tours that previously showed "Unavailable".
- **SC-004**: When the routing service is unavailable, 100% of results still show the straight-line tour and the initial estimate — no blank or broken result.
- **SC-005**: Every road-tracing failure is discoverable in the application logs.

## Assumptions

- Builds on completed feature 001; the optimized stop order, the `RouteLayer` boundary (001 FR-019), and the broadcast/result flow are reused.
- OpenStreet exposes a `/route` endpoint of the form `GET .../api/route/?origin=lat,lng&destination=lat,lng&mode=...&key=...`. **Its exact response shape (encoded polyline vs GeoJSON vs coordinate array) and whether it supports multiple waypoints in one call are UNVERIFIED and MUST be confirmed against the live API before mapping code is written** (the TSP schema was guessed wrong in 001 — do not repeat).
- If `/route` is point-to-point only, a closed N-stop tour requires N per-leg calls; this is acceptable as a background enhancement.
- `mode=trucking` is the default (these are delivery routes) and is the same mode used for the 001 optimization (centralised in config); unit is metres/seconds to match 001. No user-facing mode selector yet.
- Geometry retrieval is server-side (FR-011); the client receives already-computed geometry from the application's own backend.
- Map rendering continues to use the existing map component; only the `RouteLayer` data source changes (per 001 FR-019), so the page/list logic is untouched.
- This feature does not change how stops are picked, optimized, or ordered — only how the resulting tour is drawn and measured.
