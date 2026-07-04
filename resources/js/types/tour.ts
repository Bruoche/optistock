// Front-end view models for Delivery Route Optimization (feature 001).
// Mirrors the backend HTTP/WS payloads — see specs/.../contracts/frontend-ui.md.

/** Travel mode for a tour (feature 003). Mirrors the backend `DeliveryMode` enum. */
export type DeliveryMode = 'trucking' | 'driving' | 'walking';

/** The selectable modes in display order; trucking first (the default). */
export const DELIVERY_MODES: ReadonlyArray<{
    value: DeliveryMode;
    label: string;
}> = [
    { value: 'trucking', label: 'Trucking' },
    { value: 'driving', label: 'Driving' },
    { value: 'walking', label: 'Walking' },
];

/** One neutral (non-candidate) path piece of a driver's projected workday
 *  (feature 014), in chain order. The candidate tour is never a leg — it keeps
 *  its own highlight rendering. */
export type WorkdayLeg = {
    kind: 'connection' | 'tour';
    /** Render hint: dashed connection drive vs solid tour path. */
    dotted: boolean;
    /** `[lat, lng]` straight-line fallback points (rotated stops for a tour leg). */
    path: Array<[number, number]>;
    /** Decoded road coordinates, or null when the client traces lazily. */
    geometry: Array<[number, number]> | null;
    /** Trace flag: true only for a looping tour leg. */
    loop: boolean;
    /** True only for the connection drives bracketing the candidate tour;
     *  drawn in the primary role color at full opacity (feature 015). */
    highlight: boolean;
};

/** A delivery driver available for an optimized tour (feature 006). `modes` are
 *  the driver's supported delivery modes (one or more). */
export type Driver = {
    id: number;
    name: string;
    imageUrl: string | null;
    modes: DeliveryMode[];
    /** The warehouse this driver departs from and returns to (feature 013). */
    warehouseName: string;
    /** The driver's projected working day if given the current tour (feature 013):
     *  warehouse → first tour → … → warehouse, a best-effort total. */
    projectedSeconds: number;
    /** True when a value feeding the projection was unknown (a connection could not
     *  be routed, or a tour's own duration is unknown) — the figure is then a
     *  lower bound shown as approximate (feature 013). */
    projectedIncomplete: boolean;
    /** The stop position chosen as this driver's start for the current tour — sent
     *  back on assignment so the start is not recomputed (feature 013). */
    startIndex: number;
    /** Road time from the driver's incoming point (warehouse, or last prior tour's end)
     *  to the candidate tour; null when that connection is unroutable (feature 017). */
    timeToTour: number | null;
    /** Road time from the candidate tour back to the warehouse; null when unroutable (017). */
    timeFromTour: number | null;
    /** The drawable pieces of this driver's projected workday (feature 014). */
    legs: WorkdayLeg[];
};

/** Format a duration in seconds as a human-readable `h min` string, matching the
 *  tour-duration figure on the presentation phase. */
export function formatDurationHm(totalSeconds: number): string {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.round((totalSeconds % 3600) / 60);

    if (hours > 0) {
        return `${hours} h ${minutes.toString().padStart(2, '0')} min`;
    }

    return `${minutes} min`;
}

/** The weekday name of a YYYY-MM-DD date, for the tour date's weekday label (011).
 *  Parsed as a LOCAL calendar date (noon) so the label's weekday matches the
 *  server's date→weekday deduction and never rolls over a timezone boundary.
 *  Rendered in English (`en-US`) to match the app's English UI regardless of the
 *  browser locale. */
export function formatWeekday(date: string): string {
    const [year, month, day] = date.split('-').map(Number);
    const local = new Date(year, month - 1, day, 12);

    return local.toLocaleDateString('en-US', { weekday: 'long' });
}

/** Today's date as a LOCAL YYYY-MM-DD (the default tour date). */
export function todayDate(): string {
    return new Date().toLocaleDateString('sv-SE');
}

/** Default delivery duration (minutes) assigned to every new stop (feature 007). */
export const DEFAULT_STOP_DURATION_MINUTES = 10;

/** Per-stop ceiling (minutes, 24 h) — blocks absurd/overflow input (feature 007). */
export const MAX_STOP_DURATION_MINUTES = 1440;

/** A coordinate the planner placed on the map (client-side only). */
export type Stop = {
    /** Client-generated id for list keys + removal. */
    id: string;
    lat: number;
    lng: number;
    /** Minutes spent delivering at this stop (feature 007); default 10. */
    durationMinutes: number;
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
    /** The persisted tour id (feature 012) — the handle the geometry update and the
     *  assignment target need. */
    id: number;
    ordered_stops: OptimizedStop[];
    total_distance_m: number | null;
    total_duration_s: number | null;
};

/** Failure payload `error`. `persist_failed` = the route was optimized but could not
 *  be saved (feature 012); it is surfaced and never offered for assignment. */
export type TourError = {
    code:
        | 'api_error'
        | 'timeout'
        | 'invalid_response'
        | 'job_failed'
        | 'persist_failed';
    message: string;
};

/** Optimization flow state machine. The chosen `mode` (003) and `loop` (004) are
 *  carried from submit through to the result so the geometry trace stays congruent
 *  (FR-007). `loop` true = closed tour returning to origin; false = open one-way. */
export type OptimizeState =
    | { status: 'idle' }
    | { status: 'submitting'; mode: DeliveryMode; loop: boolean }
    | { status: 'pending'; jobUuid: string; mode: DeliveryMode; loop: boolean }
    | { status: 'done'; result: TourResult; mode: DeliveryMode; loop: boolean }
    | { status: 'failed'; error: TourError };

/** Ordered path fed to the RouteLayer boundary (FR-019). */
export type RoutePath = Array<{ lat: number; lng: number }>;

// --- Road-accurate route tracing (feature 002) ---

/** Road geometry + metrics for a single leg, or a fallback marker when it failed.
 *  `coordinates` are `[lat, lng]` pairs (the backend's decoded polyline). */
export type LegGeometry =
    | {
          ok: true;
          coordinates: Array<[number, number]>;
          distance_m: number;
          duration_s: number;
      }
    | { ok: false };

/** Aggregated road geometry for the whole closed tour (response of /api/tour/geometry). */
export type TourGeometry = {
    legs: LegGeometry[];
    total_distance_m: number | null;
    total_duration_s: number | null;
};
