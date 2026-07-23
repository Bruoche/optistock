import { renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useDriverDay } from './use-driver-day';

function dayPayload(date: string) {
    return {
        data: {
            driver: {
                id: 1,
                name: 'Amelie',
                image_url: null,
                modes: ['driving'],
                warehouse_id: 3,
                warehouse_name: 'North Depot',
                warehouse_coordinate: [48.8, 2.3],
            },
            date,
            mode: 'driving',
            workday: {
                total_seconds: 1400,
                driven_seconds: 900,
                stop_seconds: 500,
                break_seconds: 0,
                incomplete: false,
            },
            tours: [],
            legs: [],
        },
    };
}

describe('useDriverDay', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('reports loading then ready with the mapped day', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify(dayPayload('2026-07-06')), {
                status: 200,
            }),
        );

        const { result } = renderHook(() => useDriverDay(1, '2026-07-06'));

        expect(result.current.status).toBe('loading');

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.day?.driver.name).toBe('Amelie');
        expect(result.current.day?.workday.totalSeconds).toBe(1400);
    });

    it('reports error and no fabricated day on a failed fetch', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response('nope', { status: 500 }),
        );

        const { result } = renderHook(() => useDriverDay(1, '2026-07-06'));

        await waitFor(() => expect(result.current.status).toBe('error'));
        expect(result.current.day).toBeNull();
    });

    it('discards a late response for an abandoned date (no stale overwrite)', async () => {
        // First (slow) fetch for the initial date resolves AFTER we move to a new date.
        let resolveFirst!: (value: Response) => void;
        const firstPending = new Promise<Response>((resolve) => {
            resolveFirst = resolve;
        });

        const fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockImplementationOnce(() => firstPending)
            .mockResolvedValue(
                new Response(JSON.stringify(dayPayload('2026-07-07')), {
                    status: 200,
                }),
            );

        const { result, rerender } = renderHook(
            ({ date }) => useDriverDay(1, date),
            { initialProps: { date: '2026-07-06' } },
        );

        rerender({ date: '2026-07-07' });
        await waitFor(() =>
            expect(result.current.day?.date).toBe('2026-07-07'),
        );

        // The stale first response now arrives — it must not overwrite the new day.
        resolveFirst(
            new Response(JSON.stringify(dayPayload('2026-07-06')), {
                status: 200,
            }),
        );

        await Promise.resolve();
        expect(result.current.day?.date).toBe('2026-07-07');
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });
});
