// Fetches a driver's planned day (feature 025) for the selected date; re-fetches on
// date change with request cancellation, so a late response for an abandoned date can
// never overwrite the current one (FR-039). Reports loading/error fallbacks like the
// tour page — a value is never shown before its data arrives, never fabricated on failure.
import { useEffect, useState } from 'react';
import type { DriverDay } from '@/types/driver';
import type { DeliveryMode, WorkdayLeg } from '@/types/tour';

type DayStatus = 'loading' | 'ready' | 'error';

type ApiDayStop = {
    index: number;
    lat: number;
    lng: number;
    duration_s: number;
};

type ApiDayTour = {
    id: number;
    sequence: number;
    loop: boolean;
    total_duration_s: number | null;
    driven_duration_s: number | null;
    stop_seconds: number;
    start_coordinate: [number, number];
    stops: ApiDayStop[];
};

type ApiDriverDay = {
    driver: {
        id: number;
        name: string;
        image_url: string | null;
        modes: DeliveryMode[];
        warehouse_id: number;
        warehouse_name: string;
        warehouse_coordinate: [number, number];
    };
    date: string;
    mode: DeliveryMode | null;
    workday: {
        total_seconds: number;
        driven_seconds: number;
        stop_seconds: number;
        break_seconds: number;
        incomplete: boolean;
    };
    tours: ApiDayTour[];
    legs: WorkdayLeg[];
};

function toDriverDay(payload: ApiDriverDay): DriverDay {
    return {
        driver: {
            id: payload.driver.id,
            name: payload.driver.name,
            imageUrl: payload.driver.image_url,
            modes: payload.driver.modes,
            warehouseId: payload.driver.warehouse_id,
            warehouseName: payload.driver.warehouse_name,
            warehouseCoordinate: payload.driver.warehouse_coordinate,
        },
        date: payload.date,
        mode: payload.mode,
        workday: {
            totalSeconds: payload.workday.total_seconds,
            drivenSeconds: payload.workday.driven_seconds,
            stopSeconds: payload.workday.stop_seconds,
            breakSeconds: payload.workday.break_seconds,
            incomplete: payload.workday.incomplete,
        },
        tours: payload.tours.map((tour) => ({
            id: tour.id,
            sequence: tour.sequence,
            loop: tour.loop,
            totalDurationS: tour.total_duration_s,
            drivenDurationS: tour.driven_duration_s,
            stopSeconds: tour.stop_seconds,
            startCoordinate: tour.start_coordinate,
            stops: tour.stops.map((stop) => ({
                index: stop.index,
                lat: stop.lat,
                lng: stop.lng,
                durationS: stop.duration_s,
            })),
        })),
        legs: payload.legs,
    };
}

type FetchState = {
    driverId: number;
    date: string;
    day: DriverDay | null;
    status: DayStatus;
};

export function useDriverDay(
    driverId: number,
    date: string,
    /** Bump to force a re-fetch of the same driver+date (e.g. after a driver-detail save). */
    reloadToken = 0,
): { day: DriverDay | null; status: DayStatus } {
    const [state, setState] = useState<FetchState>({
        driverId,
        date,
        day: null,
        status: 'loading',
    });

    useEffect(() => {
        let cancelled = false;

        const query = `date=${encodeURIComponent(date)}`;

        fetch(`/api/driver/${driverId}/day?${query}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const payload = await response.json();

                if (cancelled) {
                    return;
                }

                setState({
                    driverId,
                    date,
                    day: toDriverDay(payload.data as ApiDriverDay),
                    status: 'ready',
                });
            })
            .catch(() => {
                if (!cancelled) {
                    setState({ driverId, date, day: null, status: 'error' });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [driverId, date, reloadToken]);

    // Until the fetch for the current driver+date resolves, report loading (no stale day).
    if (state.driverId !== driverId || state.date !== date) {
        return { day: null, status: 'loading' };
    }

    return { day: state.day, status: state.status };
}
