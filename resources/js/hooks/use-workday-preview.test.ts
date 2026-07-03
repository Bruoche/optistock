import { renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useWorkdayPreview } from './use-workday-preview';
import type { Driver, WorkdayLeg } from '@/types/tour';

const mockPostJson = vi.fn();

vi.mock('@/lib/http', () => ({
    postJson: (url: string, body: unknown) => mockPostJson(url, body),
}));

function leg(overrides: Partial<WorkdayLeg> = {}): WorkdayLeg {
    return {
        kind: 'tour',
        dotted: false,
        path: [
            [48.7, 2.1],
            [48.71, 2.11],
        ],
        geometry: null,
        loop: false,
        ...overrides,
    };
}

function driver(id: number, legs: WorkdayLeg[]): Driver {
    return {
        id,
        name: `Driver ${id}`,
        imageUrl: null,
        modes: ['driving'],
        warehouseName: 'North Depot',
        projectedSeconds: 0,
        projectedIncomplete: false,
        startIndex: 0,
        legs,
    };
}

function geometryResponse(coordinates: Array<[number, number]>) {
    return {
        ok: true,
        json: async () => ({
            legs: [{ ok: true, coordinates, distance_m: 1, duration_s: 1 }],
            total_distance_m: 1,
            total_duration_s: 1,
        }),
    };
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((res) => {
        resolve = res;
    });

    return { promise, resolve };
}

describe('useWorkdayPreview', () => {
    beforeEach(() => {
        mockPostJson.mockReset();
    });

    it('returns the legs immediately with their straight fallbacks', () => {
        mockPostJson.mockReturnValue(new Promise(() => {}));
        const selected = driver(1, [leg()]);

        const { result } = renderHook(() =>
            useWorkdayPreview(selected, 'driving', 'load-1'),
        );

        expect(result.current).toHaveLength(1);
        expect(result.current[0].geometry).toBeNull();
        expect(result.current[0].path).toEqual(selected.legs[0].path);
    });

    it('traces only the legs without geometry, without any tour_id', async () => {
        mockPostJson.mockResolvedValue(
            geometryResponse([
                [48.7, 2.1],
                [48.705, 2.105],
                [48.71, 2.11],
            ]),
        );
        const alreadyRouted = leg({
            kind: 'connection',
            dotted: true,
            geometry: [
                [1, 1],
                [2, 2],
            ],
        });
        const selected = driver(1, [alreadyRouted, leg({ loop: true })]);

        const { result } = renderHook(() =>
            useWorkdayPreview(selected, 'driving', 'load-1'),
        );

        await waitFor(() => expect(mockPostJson).toHaveBeenCalledTimes(1));
        expect(mockPostJson).toHaveBeenCalledWith('/api/tour/geometry', {
            stops: selected.legs[1].path,
            mode: 'driving',
            loop: true,
        });

        await waitFor(() =>
            expect(result.current[1].geometry).toEqual([
                [48.7, 2.1],
                [48.705, 2.105],
                [48.71, 2.11],
            ]),
        );
        expect(result.current[0].geometry).toEqual(alreadyRouted.geometry);
    });

    it('keeps the straight path when the trace fails and leaves other legs intact', async () => {
        mockPostJson.mockResolvedValueOnce({ ok: false }).mockResolvedValueOnce(
            geometryResponse([
                [9, 9],
                [10, 10],
            ]),
        );
        const failing = leg();
        const succeeding = leg({
            path: [
                [50, 3],
                [51, 4],
            ],
        });
        const selected = driver(1, [failing, succeeding]);

        const { result } = renderHook(() =>
            useWorkdayPreview(selected, 'driving', 'load-1'),
        );

        await waitFor(() =>
            expect(result.current[1].geometry).toEqual([
                [9, 9],
                [10, 10],
            ]),
        );
        expect(result.current[0].geometry).toBeNull();
        expect(result.current[0].path).toEqual(failing.path);
    });

    it('does not trace a coincident leg — its zero-length straight display is already exact', async () => {
        mockPostJson.mockResolvedValue(
            geometryResponse([
                [48.7, 2.1],
                [48.71, 2.11],
            ]),
        );
        const coincident = leg({
            kind: 'connection',
            dotted: true,
            path: [
                [48.7, 2.1],
                [48.7, 2.1],
            ],
        });
        const selected = driver(1, [coincident, leg()]);

        renderHook(() => useWorkdayPreview(selected, 'driving', 'load-1'));

        await waitFor(() => expect(mockPostJson).toHaveBeenCalledTimes(1));
        expect(mockPostJson).toHaveBeenCalledWith('/api/tour/geometry', {
            stops: selected.legs[1].path,
            mode: 'driving',
            loop: false,
        });
    });

    it('drops trace hops beyond the leg points instead of drawing undefined coordinates', async () => {
        // Malformed response: three hops for a two-point leg. The overflow hop
        // must be dropped, not pushed as undefined into the drawn path.
        mockPostJson.mockResolvedValue({
            ok: true,
            json: async () => ({
                legs: [
                    {
                        ok: true,
                        coordinates: [
                            [48.7, 2.1],
                            [48.71, 2.11],
                        ],
                        distance_m: 1,
                        duration_s: 1,
                    },
                    { ok: false },
                    { ok: false },
                ],
                total_distance_m: null,
                total_duration_s: null,
            }),
        });
        const selected = driver(1, [leg()]);

        const { result } = renderHook(() =>
            useWorkdayPreview(selected, 'driving', 'load-1'),
        );

        await waitFor(() => expect(result.current[0].geometry).not.toBeNull());
        expect(result.current[0].geometry).toEqual([
            [48.7, 2.1],
            [48.71, 2.11],
            [48.71, 2.11],
            [48.7, 2.1],
        ]);
    });

    it('drops a trace that resolves after the selection switched', async () => {
        const slow = deferred<unknown>();
        mockPostJson.mockReturnValueOnce(slow.promise);
        const driverA = driver(1, [leg()]);
        const driverB = driver(2, [
            leg({
                path: [
                    [40, 1],
                    [41, 2],
                ],
                geometry: [
                    [40, 1],
                    [41, 2],
                ],
            }),
        ]);

        const { result, rerender } = renderHook(
            ({ selected }) => useWorkdayPreview(selected, 'driving', 'load-1'),
            { initialProps: { selected: driverA } },
        );

        rerender({ selected: driverB });
        slow.resolve(
            geometryResponse([
                [48.7, 2.1],
                [48.71, 2.11],
            ]),
        );
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(result.current).toHaveLength(1);
        expect(result.current[0].path).toEqual(driverB.legs[0].path);
        expect(mockPostJson).toHaveBeenCalledTimes(1);
    });

    it('serves a re-selected driver from the cache without refetching', async () => {
        mockPostJson.mockResolvedValue(
            geometryResponse([
                [48.7, 2.1],
                [48.71, 2.11],
            ]),
        );
        const driverA = driver(1, [leg()]);

        const { result, rerender } = renderHook(
            ({ selected }: { selected: Driver | null }) =>
                useWorkdayPreview(selected, 'driving', 'load-1'),
            { initialProps: { selected: driverA as Driver | null } },
        );

        await waitFor(() => expect(result.current[0].geometry).not.toBeNull());

        rerender({ selected: null });
        expect(result.current).toEqual([]);

        rerender({ selected: driverA });
        expect(result.current[0].geometry).toEqual([
            [48.7, 2.1],
            [48.71, 2.11],
        ]);
        expect(mockPostJson).toHaveBeenCalledTimes(1);
    });

    it('clears the cache when the driver list reloads', async () => {
        mockPostJson.mockResolvedValue(
            geometryResponse([
                [48.7, 2.1],
                [48.71, 2.11],
            ]),
        );
        const driverA = driver(1, [leg()]);

        const { result, rerender } = renderHook(
            ({ selected, cacheKey }) =>
                useWorkdayPreview(selected, 'driving', cacheKey),
            { initialProps: { selected: driverA, cacheKey: 'load-1' } },
        );

        await waitFor(() => expect(mockPostJson).toHaveBeenCalledTimes(1));

        rerender({ selected: driverA, cacheKey: 'load-2' });

        await waitFor(() => expect(mockPostJson).toHaveBeenCalledTimes(2));
        await waitFor(() => expect(result.current[0].geometry).not.toBeNull());
    });
});
