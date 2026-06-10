// Fetches the drivers available for an optimized tour's mode (feature 006).
// A plain authenticated GET, mirroring the status-poll fetch pattern; re-fetches
// whenever the mode changes (e.g. a new tour optimized for a different mode).
import { useEffect, useState } from 'react';
import type { DeliveryMode, Driver } from '@/types/tour';

type DriversStatus = 'loading' | 'ready' | 'error';

type ApiDriver = {
    id: number;
    name: string;
    image_url: string | null;
    modes: DeliveryMode[];
};

type FetchState = {
    mode: DeliveryMode;
    drivers: Driver[];
    status: DriversStatus;
};

export function useTourDrivers(mode: DeliveryMode): {
    drivers: Driver[];
    status: DriversStatus;
} {
    const [state, setState] = useState<FetchState>({
        mode,
        drivers: [],
        status: 'loading',
    });

    useEffect(() => {
        let cancelled = false;

        fetch(`/api/tour/drivers?mode=${encodeURIComponent(mode)}`, {
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
                }));
                setState({ mode, drivers, status: 'ready' });
            })
            .catch(() => {
                if (!cancelled) {
                    setState({ mode, drivers: [], status: 'error' });
                }
            });

        return () => {
            cancelled = true;
        };
    }, [mode]);

    // Until the fetch for the current mode resolves, report loading (no stale list).
    if (state.mode !== mode) {
        return { drivers: [], status: 'loading' };
    }

    return { drivers: state.drivers, status: state.status };
}
