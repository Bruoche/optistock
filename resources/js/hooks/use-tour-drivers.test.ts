import { renderHook, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useTourDrivers } from './use-tour-drivers';

function fakeFetchOnce(data: unknown) {
    return vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ data }),
    });
}

const apiDriver = (overrides: Record<string, unknown> = {}) => ({
    id: 1,
    name: 'Amelie',
    image_url: null,
    modes: ['driving'],
    warehouse_name: 'North Depot',
    projected_seconds: 720,
    projected_incomplete: false,
    time_to_tour: 60,
    time_from_tour: 90,
    start_index: 0,
    warehouse_coordinate: [48.85, 2.35],
    previous_tour_end: null,
    legs: [],
    ...overrides,
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('useTourDrivers', () => {
    it('maps time_to_tour / time_from_tour onto the driver view model', async () => {
        vi.stubGlobal('fetch', fakeFetchOnce([apiDriver()]));

        const { result } = renderHook(() =>
            useTourDrivers('driving', '2026-07-06', 42),
        );

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.drivers[0].timeToTour).toBe(60);
        expect(result.current.drivers[0].timeFromTour).toBe(90);
    });

    it('maps an unroutable bracketing leg as null', async () => {
        vi.stubGlobal(
            'fetch',
            fakeFetchOnce([
                apiDriver({ time_to_tour: null, time_from_tour: null }),
            ]),
        );

        const { result } = renderHook(() =>
            useTourDrivers('driving', '2026-07-06', 42),
        );

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.drivers[0].timeToTour).toBeNull();
        expect(result.current.drivers[0].timeFromTour).toBeNull();
    });

    it('maps warehouse_coordinate and previous_tour_end onto the driver view model', async () => {
        vi.stubGlobal(
            'fetch',
            fakeFetchOnce([
                apiDriver({
                    warehouse_coordinate: [48.5, 2.5],
                    previous_tour_end: [48.71, 2.11],
                }),
            ]),
        );

        const { result } = renderHook(() =>
            useTourDrivers('driving', '2026-07-06', 42),
        );

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.drivers[0].warehouseCoordinate).toEqual([
            48.5, 2.5,
        ]);
        expect(result.current.drivers[0].previousTourEnd).toEqual([
            48.71, 2.11,
        ]);
    });

    it('maps a null previous_tour_end (driver departs from the warehouse)', async () => {
        vi.stubGlobal('fetch', fakeFetchOnce([apiDriver()]));

        const { result } = renderHook(() =>
            useTourDrivers('driving', '2026-07-06', 42),
        );

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.drivers[0].previousTourEnd).toBeNull();
    });
});
