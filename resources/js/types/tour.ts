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

/** Success payload `data`. */
export type TourResult = {
    ordered_stops: OptimizedStop[];
    total_distance_m: number;
    total_duration_s: number;
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
