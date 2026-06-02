# Feature Specification: Delivery Route Optimization

**Feature Branch**: `001-delivery-route-optimization`

**Created**: 2026-06-02

**Status**: Draft

**Input**: User description: "Build an application that use the open-street API to find the most optimised route for a series of adresses, in the optic of optimising delivery routes. The user must be able to select adresses and get the result of what route is best optimised."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Submit coordinate pairs and compute the best route (Priority: P1)

A delivery planner enters a set of coordinate pairs (`[lat, lng]`), submits them for optimization, and receives the best route order for a single delivery run.

**Why this priority**: This is the core value of the feature; without it the application cannot support optimized deliveries.

**Independent Test**: A user can enter at least two coordinate pairs, submit them, and receive a route order and optimization summary via WebSocket notification.

**Acceptance Scenarios**:

1. **Given** a planner has entered two or more valid coordinate pairs, **when** they request optimization, **then** the system returns HTTP 202 immediately, then notifies the frontend via WebSocket with an ordered route covering all submitted coordinates and summary metrics.
2. **Given** a planner submits a malformed or out-of-range coordinate, **when** they request optimization, **then** the system returns a clear validation error identifying the invalid coordinate and does not dispatch a route job.

---

### User Story 2 - Review and adjust coordinates before optimization (Priority: P2) [DEFERRED]

> **DEFERRED**: Address geocoding and address-management UI are out of scope for this feature. This user story will be implemented in a future feature branch. Current feature accepts coordinate pairs directly.

A planner can review selected coordinates, remove or re-include items, and confirm the final set before asking for the optimized route.

**Acceptance Scenarios**:

1. **Given** a planner has an active coordinate list, **when** they remove a coordinate or add a new one, **then** the system updates the selection and uses the final list for optimization.

---

### User Story 3 - Understand the optimized result and route details (Priority: P3)

A planner can see the best route result with total distance or travel estimate, the ordered list of stops, and a simple explanation of why the result is optimal.

**Why this priority**: Clear results make the planner confident in the recommendation and support faster decision-making.

**Independent Test**: After optimization, the system displays route details and a route summary that matches the selected addresses.

**Acceptance Scenarios**:

1. **Given** a planner has a valid optimized route, **when** the result is displayed, **then** the system shows the ordered stop list, total route estimate, and route quality summary.

---

### Edge Cases

- What happens when the planner submits only one coordinate pair? The system should explain that at least two coordinates are needed for route optimization.
- How does the system handle duplicate or identical coordinates? The system should detect duplicates and warn the user or collapse them before optimization.
- What happens if the OpenStreet TSP API is unavailable or returns an error? The system should broadcast a failure event to the frontend with a friendly error message.
- What if the queue worker crashes or the job never runs? The system must broadcast a failure event (via `OptimizeRouteJob::failed()`) so the frontend is never stuck waiting indefinitely.
- What if the TSP API returns an unreachable or invalid route? The system should broadcast the error payload so the frontend surfaces it clearly.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST allow a user to enter a list of coordinate pairs (`[lat, lng]`) for route optimization.
- **FR-002** [DEFERRED]: The system MUST allow the user to review, add, remove, and confirm the final set of selected coordinates before optimization.
- **FR-003**: The system MUST submit the selected coordinate pairs to the OpenStreet TSP API and request the most optimized route order via an asynchronous background job.
- **FR-004**: The system MUST notify the frontend via WebSocket with the optimized route result as an ordered list of stops once the background job completes.
- **FR-005**: The system MUST include summary metrics in the WebSocket result payload (total distance in metres, total duration in seconds).
- **FR-006**: The system MUST return a validation error for invalid coordinates (malformed, out-of-range, or fewer than 2 points) and prevent dispatching a route job until corrected.
- **FR-007** [DEFERRED]: The system MUST preserve the selected coordinate list until the user clears it or submits a new optimization request.
- **FR-008**: The system MUST allow the user to submit a new optimization request after the previous result is received or cleared.

### Key Entities *(include if feature involves data)*

- **Delivery Location**: A delivery stop represented as a coordinate pair `{ lat: float, lng: float }` submitted by the user for route optimization.
- **Route Optimization Request**: The array of coordinate pairs submitted to the TSP API via a background job, identified by a `job_uuid`.
- **Optimized Route**: The ordered result returned by the TSP API, broadcast to the frontend as `ordered_stops`, `total_distance_m`, and `total_duration_s`.
- **Route Summary**: The compact payload stored in Redis and broadcast via WebSocket: `{ job_uuid, data: { ordered_stops, total_distance_m, total_duration_s } }`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can select addresses and receive an optimized delivery route recommendation in a single flow.
- **SC-002**: The system returns a route recommendation for valid address sets within 10 seconds for up to 10 selected locations.
- **SC-003**: At least 90% of valid optimization requests return a route with a complete ordered stop list and summary metrics.
- **SC-004** [DEFERRED]: The system identifies invalid or unresolvable addresses in the selected list and shows a corrective message in at least 95% of those cases.
- **SC-005**: The route result is presented in a way that a planner can tell the visit order and total route estimate without extra explanation.

## Assumptions

- The application will use the OpenStreetMap-compatible routing service to compute optimized delivery routes.
- Users have internet connectivity and can access the external routing API during optimization.
- This feature is focused on planning a single delivery route, not on multi-vehicle dispatch or live driver tracking.
- Address input is provided as street-level address information or similarly resolvable location details.
- A minimum of two delivery addresses is required for meaningful route optimization.
- User authentication is a prerequisite dependency for this feature (required for per-user cache isolation and private WebSocket channels); implementing an auth system is outside the scope of this feature.
- The frontend submits coordinates directly (`[lat, lng]` pairs); address geocoding is out of scope for this feature.
- Routes are closed-tour by default (return to origin); `tour=closed` is submitted to the TSP API.
