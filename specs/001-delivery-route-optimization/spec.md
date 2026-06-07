# Feature Specification: Delivery Route Optimization

**Feature Branch**: `001-delivery-route-optimization`

**Created**: 2026-06-02

**Status**: Backend complete + verified; front-end specified/planned (updated 2026-06-07)

**Input**: User description: "Build an application that use the open-street API to find the most optimised route for a series of adresses, in the optic of optimising delivery routes. The user must be able to select adresses and get the result of what route is best optimised."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Submit coordinate pairs and compute the best route (Priority: P1)

A delivery planner enters a set of coordinate pairs (`[lat, lng]`), submits them for optimization, and receives the best route order for a single delivery run.

**Why this priority**: This is the core value of the feature; without it the application cannot support optimized deliveries.

**Independent Test**: A user can enter at least two coordinate pairs, submit them, and receive a route order and optimization summary via WebSocket notification.

**Acceptance Scenarios**:

1. **Given** a planner has entered two or more valid coordinate pairs, **when** they request optimization, **then** the system returns HTTP 202 immediately, then notifies the frontend via WebSocket with an ordered route covering all submitted coordinates and summary metrics.
2. **Given** a planner submits a malformed or out-of-range coordinate, **when** they request optimization, **then** the system returns a clear validation error identifying the invalid coordinate and does not dispatch a route job.
3. **Given** a planner is on the optimization screen, **when** they click a location on the interactive map, **then** a stop is added to the tour and appears both as a marker on the map and as an entry in the coordinate list beneath the map.
4. **Given** a planner has at least two stops, **when** they press the "Optimize" button above the list, **then** the list becomes greyed out and non-editable, and a small horizontal bar at the bottom shows an "Optimizing..." message with a rotating loading indicator until a result or failure arrives.

---

### User Story 2 - Review and adjust selected stops before optimization (Priority: P2)

> **NOTE**: Only the *coordinate-list review/remove* portion is in scope (managing pins the user dropped on the map). **Address geocoding / address-search input remains DEFERRED** to a future feature — stops are still created by picking points on the map, not by typing addresses.

A planner can review the stops they placed on the map as a list beneath the map, remove any stop that was added by mistake, and confirm the final set before requesting the optimized route.

**Acceptance Scenarios**:

1. **Given** a planner has placed several stops on the map, **when** they remove a stop from the list, **then** the system removes the matching marker from the map and excludes that stop from the next optimization request.
2. **Given** a planner has placed only one or zero stops, **when** they look at the "Optimize" button, **then** the button is disabled (optimization needs at least two stops) until a second stop is added.

---

### User Story 3 - Understand the optimized result and route details (Priority: P3)

A planner can see the best route result with total distance or travel estimate, the ordered list of stops, and a simple explanation of why the result is optimal.

**Why this priority**: Clear results make the planner confident in the recommendation and support faster decision-making.

**Independent Test**: After optimization, the system displays route details and a route summary that matches the selected stops.

**Acceptance Scenarios**:

1. **Given** a planner has a valid optimized route, **when** the result is displayed, **then** the system shows the optimized route on the map and replaces the coordinate list area with the tour result; for this feature the result area shows the total tour duration at the top (in the position the "Optimize" button occupied).
2. **Given** an optimization result arrives via WebSocket, **when** the frontend updates, **then** the "Optimizing..." loading bar is removed and the optimized ordered route is rendered without the user reloading the page.
3. **Given** the lower area previously held the editable stop list, **when** a result is shown, **then** the space the list occupied is left available for future content (a planned drivers list — out of scope for this feature) and is not filled with unnecessary information.

---

### Edge Cases

- What happens when the planner submits only one coordinate pair? The system should explain that at least two coordinates are needed for route optimization.
- How does the system handle duplicate or identical coordinates? The backend `CoordinateNormalizer` rounds (5 decimals) and produces a canonical, de-duplicated list before optimization — identical stops collapse **silently** (no user-facing warning in this feature). A "duplicate ignored" hint to the user is a possible future enhancement, not in scope here.
- What happens if the OpenStreet TSP API is unavailable or returns an error? The system should broadcast a failure event to the frontend with a friendly error message.
- What if the queue worker crashes or the job never runs? The system must broadcast a failure event (via `OptimizeTourJob::failed()`) so the frontend is never stuck waiting indefinitely.
- What if the TSP API returns an unreachable or invalid route? The system should broadcast the error payload so the frontend surfaces it clearly.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST allow a user to enter a list of coordinate pairs (`[lat, lng]`) for route optimization by picking points on an interactive map.
- **FR-002**: The system MUST allow the user to review and remove selected stops (shown as a list beneath the map) and confirm the final set before optimization. (Adding stops is done by picking points on the map; typing/searching addresses remains deferred.)
- **FR-003**: The system MUST submit the selected coordinate pairs to the OpenStreet TSP API and request the most optimized route order via an asynchronous background job.
- **FR-004**: The system MUST notify the frontend via WebSocket with the optimized route result as an ordered list of stops once the background job completes.
- **FR-005**: The system MUST include summary metrics in the WebSocket result payload (total distance in metres, total duration in seconds).
- **FR-006**: The system MUST return a validation error for invalid coordinates (malformed, out-of-range, or fewer than 2 points) and prevent dispatching a route job until corrected.
- **FR-007**: The system MUST preserve the selected stop list until the user clears it or submits a new optimization request.
- **FR-008**: The system MUST allow the user to submit a new optimization request after the previous result is received or cleared.

### User Interface & Layout Requirements

- **FR-009**: The screen MUST place an interactive map at the top-center occupying approximately two-thirds (2/3) of the vertical space; the stop list and controls occupy the remaining lower third.
- **FR-010**: The system MUST show selected stops as a list directly beneath the map, with each entry individually removable so a planner can correct a mistaken pick.
- **FR-011**: The system MUST display an "Optimize" action button positioned at the top of the stop list; it MUST be disabled until at least two stops are selected.
- **FR-012**: When optimization is requested, the system MUST grey out and disable the stop list to indicate it cannot be edited while a calculation is in progress.
- **FR-013**: While optimization is in progress, the system MUST show a small horizontal status bar at the bottom of the screen displaying an "Optimizing..." message with a rotating loading indicator.
- **FR-014**: When the optimization result arrives via WebSocket, the system MUST remove the loading bar, render the optimized route on the map, and replace the stop-list area's controls so the total tour duration is shown at the top (where the "Optimize" button was).
- **FR-015**: The lower-area space previously occupied by the stop list MUST be left free of unnecessary content after a result (reserved for a future drivers list, out of scope here).
- **FR-016**: The interface MUST be minimalist and legible — no distracting/decorative effects, and no unnecessary information beyond what the planner needs to act.

### Visual Design Requirements

- **FR-017**: The background MUST be pure white (`#FFFFFF`). The primary color MUST be orange `#FF9A3C` (e.g., the primary "Optimize"/"Submit" action). A pale orange secondary `#FFCF8C` MUST be used for lower-importance elements placed next to a primary element (e.g., a "Cancel" control).
- **FR-018**: The yellow accent `#FFC802` MUST be used very sparingly — only to make a single element stand out on a primary-heavy area, and optionally in gradients (gradients themselves used sparingly). Text MUST be black even on colored (orange/yellow) backgrounds for legibility. In dark mode, text on colored fills uses near-black `#11100F` (the `text-on-color` role).
- **FR-019**: The system MUST draw the optimized route as straight-line segments connecting the ordered stops. The route-line rendering MUST be isolated behind a single component/data boundary that takes a list of path coordinates, so swapping straight segments for road-accurate geometry later requires changing only that boundary (not the page or list logic).

### Key Entities *(include if feature involves data)*

- **Delivery Location**: A delivery stop represented as a coordinate pair `{ lat: float, lng: float }` submitted by the user for route optimization.
- **Route Optimization Request**: The array of coordinate pairs submitted to the TSP API via a background job, identified by a `job_uuid`.
- **Optimized Route**: The ordered result returned by the TSP API, broadcast to the frontend as `ordered_stops`, `total_distance_m`, and `total_duration_s`.
- **Route Summary**: The compact payload stored in the cache (database store) and broadcast via WebSocket: `{ job_uuid, data: { ordered_stops, total_distance_m, total_duration_s } }`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can select stops (map-picked coordinates) and receive an optimized delivery route recommendation in a single flow.
- **SC-002**: The system returns a route recommendation for valid coordinate sets within 10 seconds for up to 10 selected stops.
- **SC-003**: At least 90% of valid optimization requests return a route with a complete ordered stop list and summary metrics.
- **SC-004** [DEFERRED]: The system identifies invalid or unresolvable addresses in the selected list and shows a corrective message in at least 95% of those cases.
- **SC-005**: The route result is presented in a way that a planner can tell the visit order and total route estimate without extra explanation.
- **SC-006**: A planner can place stops on the map, remove a mistaken stop, and launch optimization using only on-screen controls, with no written instructions needed.
- **SC-007**: The optimized route's total duration is visible at the top of the result area immediately after the result arrives, without a page reload.

## Assumptions

- The application will use the OpenStreetMap-compatible routing service to compute optimized delivery routes.
- Users have internet connectivity and can access the external routing API during optimization.
- This feature is focused on planning a single delivery route, not on multi-vehicle dispatch or live driver tracking.
- Address input is provided as street-level address information or similarly resolvable location details.
- A minimum of two delivery addresses is required for meaningful route optimization.
- User authentication is a prerequisite dependency for this feature (required for per-user cache isolation and private WebSocket channels); implementing an auth system is outside the scope of this feature.
- The frontend submits coordinates directly (`[lat, lng]` pairs); address geocoding / address-search input is out of scope for this feature.
- Stops are created by picking points on an interactive map; the map displays standard street-level tiles for orientation.
- The optimized route is rendered on the map as ordered stops (numbered markers) connected by **straight-line segments** in visit order, so the visit order is visible at a glance. Road-accurate tracing is a deferred enhancement (see Deferred / Future Enhancements).
- For this feature the result area surfaces only the total tour duration; the freed list space is reserved for a future drivers list (out of scope).
- A driver/vehicle selection list will occupy the lower area in a future feature; it is explicitly out of scope here.
- Routes are closed-tour by default (return to origin); `tour=closed` is submitted to the TSP API.

## Visual Design Tokens (reference)

Implemented as role CSS variables in `resources/css/app.css` (light = `:root`, dark = `.dark`); see plan.md "Theming". No raw hex at point of use (Constitution VI).

| Role | Light | Dark | Usage |
|------|-------|------|-------|
| Background | `#FFFFFF` | `#11100F` | Page background |
| Text (foreground) | `#000000` | `#FFFFFF` | Default text on the page background |
| Primary | `#FF9A3C` | `#F99435` | Primary actions/elements (e.g., "Optimize", "Submit") |
| Secondary (pale orange) | `#FFCF8C` | `#FFCF8C` | Lower-importance element beside a primary one (e.g., "Cancel") |
| Accent (yellow) | `#FFC802` | `#FFC802` | Very sparing standout / occasional gradient only |
| Text-on-color | `#000000` | `#11100F` | Text placed on a colored (primary/secondary/accent) fill, for legibility |

## Deferred / Future Enhancements

- **Road-accurate route tracing**: Replace straight-line segments with road-following geometry from the OpenStreet route endpoint (`GET https://maps.open-street.com/api/route/?origin=lat,lng&destination=lat,lng&mode=...&key=...`). Decision (2026-06-07): deferred; ship straight lines first.
  - **Design guard (in scope now)**: per FR-019, the route line is rendered from a single list-of-coordinates boundary so this swap is front-end-cheap. The page consumes a path; only the path's source changes.
  - **Open question to verify before adopting**: the endpoint is point-to-point (origin/destination, no waypoints shown) — a closed N-stop tour needs N per-leg calls. Confirm the response shape (encoded polyline vs GeoJSON vs coord array) **against the live API** before building (the TSP schema was guessed wrong once — avoid repeat).
  - **Suggested integration**: compute geometry server-side in `OptimizeTourJob` after TSP, cache it with the result, broadcast it alongside `ordered_stops` — not N browser fetches. Keeps the 202→WebSocket flow and per-user cache intact.
- **Address geocoding / address-search input**: add stops by typing an address instead of map-picking (US2 input portion).
- **Drivers list**: fill the freed lower-area space with selectable delivery drivers (replaces the result-area placeholder).
