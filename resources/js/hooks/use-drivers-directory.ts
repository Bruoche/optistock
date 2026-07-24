// Fetches the drivers matching the directory criteria (feature 027). Debounces criteria changes,
// cancels the in-flight request when they change again, and only commits the response for the
// latest criteria — so fast typing/toggling settles on the current filter with no stale list.
import { useEffect, useState } from 'react';
import type { DirectoryCriteria, DirectoryDriver } from '@/types/driver';
import type { DeliveryMode } from '@/types/tour';

type DirectoryStatus = 'loading' | 'ready' | 'error';

type ApiDriver = {
    id: number;
    name: string;
    image_url: string | null;
    modes: DeliveryMode[];
    warehouse_id: number;
    warehouse_name: string;
};

type FetchState = {
    query: string | null;
    drivers: DirectoryDriver[];
    status: DirectoryStatus;
};

const DEBOUNCE_MS = 200;

function queryFrom(criteria: DirectoryCriteria): string {
    const params = new URLSearchParams();
    const name = criteria.name.trim();

    if (name !== '') {
        params.set('name', name);
    }

    criteria.modes.forEach((mode) => params.append('modes[]', mode));

    if (criteria.warehouseId !== null) {
        params.set('warehouse', String(criteria.warehouseId));
    }

    return params.toString();
}

export function useDriversDirectory(criteria: DirectoryCriteria): {
    drivers: DirectoryDriver[];
    status: DirectoryStatus;
} {
    const query = queryFrom(criteria);
    const [state, setState] = useState<FetchState>({
        query: null,
        drivers: [],
        status: 'loading',
    });

    useEffect(() => {
        const controller = new AbortController();

        const timer = setTimeout(() => {
            const url = query ? `/api/drivers?${query}` : '/api/drivers';

            fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then(async (response) => {
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();
                    const drivers = (payload.data as ApiDriver[]).map(
                        (driver) => ({
                            id: driver.id,
                            name: driver.name,
                            imageUrl: driver.image_url,
                            modes: driver.modes,
                            warehouseId: driver.warehouse_id,
                            warehouseName: driver.warehouse_name,
                        }),
                    );

                    setState({ query, drivers, status: 'ready' });
                })
                .catch((error: unknown) => {
                    if (
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    ) {
                        return;
                    }

                    setState({ query, drivers: [], status: 'error' });
                });
        }, DEBOUNCE_MS);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    // Until the fetch for the current criteria resolves, report loading (no stale list).
    if (state.query !== query) {
        return { drivers: [], status: 'loading' };
    }

    return { drivers: state.drivers, status: state.status };
}
