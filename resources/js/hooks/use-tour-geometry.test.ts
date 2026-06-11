import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useTourGeometry } from './use-tour-geometry';
import type { TourGeometry, TourResult } from '@/types/tour';

const RESULT: TourResult = {
    ordered_stops: [
        { lat: 1, lng: 1, order: 0 },
        { lat: 2, lng: 2, order: 1 },
        { lat: 3, lng: 3, order: 2 },
    ],
    total_distance_m: 100,
    total_duration_s: 600,
};

const GEO: TourGeometry = {
    legs: [
        {
            ok: true,
            coordinates: [
                [1, 1],
                [1.5, 1.5],
            ],
            distance_m: 10,
            duration_s: 60,
        },
        {
            ok: true,
            coordinates: [
                [2, 2],
                [2.5, 2.5],
            ],
            distance_m: 10,
            duration_s: 60,
        },
        {
            ok: true,
            coordinates: [
                [3, 3],
                [1, 1],
            ],
            distance_m: 10,
            duration_s: 60,
        },
    ],
    total_distance_m: 30,
    total_duration_s: 180,
};

function jsonOk(body: unknown): Response {
    return { ok: true, status: 200, json: async () => body } as Response;
}

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('useTourGeometry', () => {
    it('shows a straight closed fallback before geometry arrives', () => {
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonOk(GEO));
        const { result } = renderHook(() =>
            useTourGeometry(RESULT, 'trucking', true),
        );

        // Synchronous first render = fallback.
        expect(result.current.closed).toBe(true);
        expect(result.current.metrics).toBeNull();
        expect(result.current.routePath).toHaveLength(3);
    });

    it('sends the tour mode in the geometry request body (003 FR-006)', async () => {
        const fetchMock = fetch as ReturnType<typeof vi.fn>;
        fetchMock.mockResolvedValue(jsonOk(GEO));
        renderHook(() => useTourGeometry(RESULT, 'walking', true));

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());

        const [, options] = fetchMock.mock.calls[0];
        expect(JSON.parse((options as RequestInit).body as string).mode).toBe(
            'walking',
        );
    });

    it('sends the loop flag and uses an open (non-closed) fallback when loop is false (004)', async () => {
        const fetchMock = fetch as ReturnType<typeof vi.fn>;
        fetchMock.mockResolvedValue(jsonOk(GEO));
        const { result } = renderHook(() =>
            useTourGeometry(RESULT, 'trucking', false),
        );

        // Synchronous first render = straight fallback; an open tour is not closed.
        expect(result.current.closed).toBe(false);

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        const [, options] = fetchMock.mock.calls[0];
        expect(JSON.parse((options as RequestInit).body as string).loop).toBe(
            false,
        );
    });

    it('replaces the fallback with the road path + road metrics on success (FR-002/FR-003)', async () => {
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonOk(GEO));
        const { result } = renderHook(() =>
            useTourGeometry(RESULT, 'trucking', true),
        );

        await waitFor(() => expect(result.current.metrics).not.toBeNull());

        expect(result.current.closed).toBe(false);
        expect(result.current.metrics).toEqual({
            distance_m: 30,
            duration_s: 180,
        });
        expect(result.current.routePath).toHaveLength(6); // 2 coords × 3 legs
        expect(result.current.routePath[0]).toEqual({ lat: 1, lng: 1 });
    });

    it('keeps the straight fallback when the fetch fails (FR-005)', async () => {
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
            ok: false,
            status: 500,
        } as Response);
        const { result } = renderHook(() =>
            useTourGeometry(RESULT, 'trucking', true),
        );

        await act(async () => {
            await Promise.resolve();
        });

        expect(result.current.closed).toBe(true);
        expect(result.current.metrics).toBeNull();
        expect(result.current.routePath).toHaveLength(3);
    });

    it('nulls road metrics when a leg failed but still draws the per-leg fallback (FR-006/FR-008)', async () => {
        const partial: TourGeometry = {
            legs: [
                {
                    ok: true,
                    coordinates: [
                        [1, 1],
                        [1.5, 1.5],
                    ],
                    distance_m: 10,
                    duration_s: 60,
                },
                { ok: false },
                { ok: false },
            ],
            total_distance_m: null,
            total_duration_s: null,
        };
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValue(jsonOk(partial));
        const { result } = renderHook(() =>
            useTourGeometry(RESULT, 'trucking', true),
        );

        await waitFor(() => expect(result.current.closed).toBe(false));

        expect(result.current.metrics).toEqual({
            distance_m: null,
            duration_s: null,
        });
        // leg0 road (2 pts) + leg1 fallback (2 pts) + leg2 fallback (2 pts)
        expect(result.current.routePath).toHaveLength(6);
    });

    it('ignores a late response for a superseded result (FR-010)', async () => {
        const resultB: TourResult = {
            ordered_stops: [
                { lat: 9, lng: 9, order: 0 },
                { lat: 8, lng: 8, order: 1 },
            ],
            total_distance_m: null,
            total_duration_s: null,
        };
        const geoB: TourGeometry = {
            legs: [
                {
                    ok: true,
                    coordinates: [[9, 9]],
                    distance_m: 5,
                    duration_s: 5,
                },
                {
                    ok: true,
                    coordinates: [[8, 8]],
                    distance_m: 5,
                    duration_s: 5,
                },
            ],
            total_distance_m: 10,
            total_duration_s: 10,
        };
        const fetchMock = fetch as ReturnType<typeof vi.fn>;
        fetchMock.mockResolvedValueOnce(jsonOk(GEO)); // for RESULT (A)
        fetchMock.mockResolvedValueOnce(jsonOk(geoB)); // for resultB

        const { result, rerender } = renderHook(
            ({ r }) => useTourGeometry(r, 'trucking', true),
            {
                initialProps: { r: RESULT as TourResult | null },
            },
        );
        rerender({ r: resultB });

        await waitFor(() =>
            expect(result.current.metrics?.distance_m).toBe(10),
        );
        // The earlier (A) response must not have won.
        expect(result.current.metrics?.distance_m).not.toBe(30);
    });
});
