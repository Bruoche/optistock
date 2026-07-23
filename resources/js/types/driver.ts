// Front-end view models for the Driver Management page (feature 025). Mirrors the
// driver-day payload — see specs/025-driver-management-page/contracts/driver-day.md.
import type { DeliveryMode, WorkdayLeg } from '@/types/tour';

/** One stop of a day tour, in driven order (index 1..N) — matches the map's stop markers. */
export type DayStop = {
    index: number;
    lat: number;
    lng: number;
    durationS: number;
};

/** One tour on a driver's day, in running order. */
export type DayTour = {
    id: number;
    sequence: number;
    loop: boolean;
    /** Travel + stop time; null when the road travel is undetermined (never a false 0). */
    totalDurationS: number | null;
    /** Road travel only; null when undetermined. */
    drivenDurationS: number | null;
    stopSeconds: number;
    /** Entry point of the tour — where its "T{n}" marker sits. */
    startCoordinate: [number, number];
    stops: DayStop[];
};

/** The day's derived workday totals. `incomplete` → a value fed the total was unknown,
 *  so the total is a lower bound shown with a warning (FR-013). */
export type DayWorkday = {
    totalSeconds: number;
    drivenSeconds: number;
    stopSeconds: number;
    breakSeconds: number;
    incomplete: boolean;
};

/** Driver identity shown (and edited) at the top of the page. */
export type DayDriver = {
    id: number;
    name: string;
    imageUrl: string | null;
    modes: DeliveryMode[];
    warehouseId: number;
    warehouseName: string;
    warehouseCoordinate: [number, number];
};

/** The whole driver-day view model, assembled from one endpoint read. */
export type DriverDay = {
    driver: DayDriver;
    date: string;
    /** The day's single mode (FR-045), or null for an empty day. */
    mode: DeliveryMode | null;
    workday: DayWorkday;
    tours: DayTour[];
    legs: WorkdayLeg[];
};

/** A selectable warehouse for the identity editor (page prop). */
export type WarehouseOption = {
    id: number;
    name: string;
};
