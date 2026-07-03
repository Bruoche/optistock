// Fetches the drivers available for an optimized tour, with each one's projected
// working day; re-fetches whenever the mode, date, or tour changes.
import { useEffect, useState } from 'react';
import type { DeliveryMode, Driver, WorkdayLeg } from '@/types/tour';

type DriversStatus = 'loading' | 'ready' | 'error';

type ApiDriver = {
    id: number;
    name: string;
    image_url: string | null;
    modes: DeliveryMode[];
    warehouse_name: string;
    projected_seconds: number;
    projected_incomplete: boolean;
    start_index: number;
    legs: WorkdayLeg[];
};

type FetchState = {
    mode: DeliveryMode;
    date: string;
    tourId: number;
    drivers: Driver[];
    status: DriversStatus;
};

export function useTourDrivers(
    mode: DeliveryMode,
    date: string,
    tourId: number,
): {
    drivers: Driver[];
    status: DriversStatus;
} {
    const [state, setState] = useState<FetchState>({
        mode,
        date,
        tourId,
        drivers: [],
        status: 'loading',
    });

    useEffect(() => {
        let cancelled = false;

        const query = `mode=${encodeURIComponent(mode)}&date=${encodeURIComponent(date)}&tour=${encodeURIComponent(tourId)}`;

        fetch(`/api/tour/drivers?${query}`, {
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

                const drivers = (payload.data as ApiDriver[]).map((driver) => ({
                    id: driver.id,
                    name: driver.name,
                    imageUrl: driver.image_url,
                    modes: driver.modes,
                    warehouseName: driver.warehouse_name,
                    projectedSeconds: driver.projected_seconds,
                    projectedIncomplete: driver.projected_incomplete,
                    startIndex: driver.start_index,
                    legs: driver.legs,
                }));
                setState({ mode, date, tourId, drivers, status: 'ready' });
            })
            .catch(() => {
                if (!cancelled) {
                    setState({
                        mode,
                        date,
                        tourId,
                        drivers: [],
                        status: 'error',
                    });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [mode, date, tourId]);

    // Until the fetch for the current mode+date+tour resolves, report loading (no stale list).
    if (state.mode !== mode || state.date !== date || state.tourId !== tourId) {
        return { drivers: [], status: 'loading' };
    }

    return { drivers: state.drivers, status: state.status };
}
