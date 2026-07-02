// Fetches the drivers available for an optimized tour's mode + date (features 006,
// 011). A plain authenticated GET, mirroring the status-poll fetch pattern;
// re-fetches whenever the mode or date changes so the list always matches the
// tour's mode and the selected date's weekday.
import { useEffect, useState } from 'react';
import type { DeliveryMode, Driver } from '@/types/tour';

type DriversStatus = 'loading' | 'ready' | 'error';

type ApiDriver = {
    id: number;
    name: string;
    image_url: string | null;
    modes: DeliveryMode[];
    assigned_seconds: number;
};

type FetchState = {
    mode: DeliveryMode;
    date: string;
    drivers: Driver[];
    status: DriversStatus;
};

export function useTourDrivers(
    mode: DeliveryMode,
    date: string,
): {
    drivers: Driver[];
    status: DriversStatus;
} {
    const [state, setState] = useState<FetchState>({
        mode,
        date,
        drivers: [],
        status: 'loading',
    });

    useEffect(() => {
        let cancelled = false;

        const query = `mode=${encodeURIComponent(mode)}&date=${encodeURIComponent(date)}`;

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
                    assignedSeconds: driver.assigned_seconds,
                }));
                setState({ mode, date, drivers, status: 'ready' });
            })
            .catch(() => {
                if (!cancelled) {
                    setState({ mode, date, drivers: [], status: 'error' });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [mode, date]);

    // Until the fetch for the current mode+date resolves, report loading (no stale list).
    if (state.mode !== mode || state.date !== date) {
        return { drivers: [], status: 'loading' };
    }

    return { drivers: state.drivers, status: state.status };
}
