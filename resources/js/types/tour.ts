// Front-end view models for Delivery Route Optimization (feature 001).
// Mirrors the backend HTTP/WS payloads — see specs/.../contracts/frontend-ui.md.

/** A coordinate the planner placed on the map (client-side only). */
export type Stop = {
    /** Client-generated id for list keys + removal. */
    id: string;
    lat: number;
    lng: number;
};

/** One stop in the optimized order (from the backend `ordered_stops`). */
export type OptimizedStop = {
    lat: number;
    lng: number;
    order: number;
};

/** Success payload `data`. Metrics are null for a 2-point tour (no routing call
 *  yet — pending the /route/ endpoint). */
export type TourResult = {
    ordered_stops: OptimizedStop[];
    total_distance_m: number | null;
    total_duration_s: number | null;
};

/** Failure payload `error`. */
export type TourError = {
    code: 'api_error' | 'timeout' | 'invalid_response' | 'job_failed';
    message: string;
};

/** Optimization flow state machine. */
export type OptimizeState =
    | { status: 'idle' }
    | { status: 'submitting' }
    | { status: 'pending'; jobUuid: string }
    | { status: 'done'; result: TourResult }
    | { status: 'failed'; error: TourError };

/** Ordered path fed to the RouteLayer boundary (FR-019). */
export type RoutePath = Array<{ lat: number; lng: number }>;

// --- Road-accurate route tracing (feature 002) ---

/** Road geometry + metrics for a single leg, or a fallback marker when it failed.
 *  `coordinates` are `[lat, lng]` pairs (the backend's decoded polyline). */
export type LegGeometry =
    | { ok: true; coordinates: Array<[number, number]>; distance_m: number; duration_s: number }
    | { ok: false };

/** Aggregated road geometry for the whole closed tour (response of /api/tour/geometry). */
export type TourGeometry = {
    legs: LegGeometry[];
    total_distance_m: number | null;
    total_duration_s: number | null;
};
