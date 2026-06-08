import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useTourOptimization } from './use-tour-optimization';

// --- Mocks ---------------------------------------------------------------

const mocks = vi.hoisted(() => {
    const handlers: Record<string, (event: unknown) => void> = {};
    const channel = {
        listen: vi.fn((event: string, cb: (event: unknown) => void) => {
            handlers[event] = cb;

            return channel;
        }),
    };
    const echo = { private: vi.fn(() => channel), leave: vi.fn() };

    return { handlers, channel, echo, toastError: vi.fn() };
});

vi.mock('@/lib/echo', () => ({ getEcho: () => mocks.echo }));
vi.mock('sonner', () => ({ toast: { error: mocks.toastError } }));

const RESULT = { ordered_stops: [], total_distance_m: 100, total_duration_s: 600 };

function jsonResponse(status: number, body: unknown): Response {
    return { status, ok: status >= 200 && status < 300, json: async () => body } as Response;
}

beforeEach(() => {
    Object.keys(mocks.handlers).forEach((k) => delete mocks.handlers[k]);
    vi.clearAllMocks();
    vi.stubGlobal('fetch', vi.fn());
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

async function addTwoStops(result: { current: ReturnType<typeof useTourOptimization> }) {
    act(() => {
        result.current.addStop(48.1, 2.1);
        result.current.addStop(48.2, 2.2);
    });
}

// --- Tests ---------------------------------------------------------------

describe('useTourOptimization', () => {
    it('stays idle and does not POST when fewer than two stops', async () => {
        const { result } = renderHook(() => useTourOptimization(7));
        act(() => result.current.addStop(48.1, 2.1));

        await act(async () => {
            await result.current.optimize('trucking');
        });

        expect(result.current.state.status).toBe('idle');
        expect(fetch).not.toHaveBeenCalled();
    });

    it('200 cache hit transitions straight to done', async () => {
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce(
            jsonResponse(200, { status: 'done', data: RESULT }),
        );
        const { result } = renderHook(() => useTourOptimization(7));
        await addTwoStops(result);

        await act(async () => {
            await result.current.optimize('trucking');
        });

        expect(result.current.state).toEqual({ status: 'done', result: RESULT, mode: 'trucking' });
    });

    it('202 cache miss goes pending, then done on the TourOptimized event (filtered by job_uuid)', async () => {
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce(
            jsonResponse(202, { status: 'pending', job_uuid: 'abc' }),
        );
        const { result } = renderHook(() => useTourOptimization(7));
        await addTwoStops(result);

        await act(async () => {
            await result.current.optimize('trucking');
        });
        expect(result.current.state.status).toBe('pending');

        // wrong job ignored
        act(() => mocks.handlers['.TourOptimized']({ job_uuid: 'other', data: RESULT }));
        expect(result.current.state.status).toBe('pending');

        // matching job resolves
        act(() => mocks.handlers['.TourOptimized']({ job_uuid: 'abc', data: RESULT }));
        expect(result.current.state).toEqual({ status: 'done', result: RESULT, mode: 'trucking' });
        expect(mocks.echo.leave).toHaveBeenCalledWith('App.Models.User.7');
    });

    it('fails and toasts on the TourOptimizationFailed event', async () => {
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce(
            jsonResponse(202, { status: 'pending', job_uuid: 'abc' }),
        );
        const { result } = renderHook(() => useTourOptimization(7));
        await addTwoStops(result);
        await act(async () => {
            await result.current.optimize('trucking');
        });

        const error = { code: 'job_failed', message: 'boom' };
        act(() => mocks.handlers['.TourOptimizationFailed']({ job_uuid: 'abc', error }));

        expect(result.current.state).toEqual({ status: 'failed', error });
        expect(mocks.toastError).toHaveBeenCalledWith('boom');
    });

    it('422 validation maps to a failed state', async () => {
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce(jsonResponse(422, {}));
        const { result } = renderHook(() => useTourOptimization(7));
        await addTwoStops(result);

        await act(async () => {
            await result.current.optimize('trucking');
        });

        expect(result.current.state.status).toBe('failed');
    });

    it('poll fallback resolves the tour when the websocket stays silent', async () => {
        vi.useFakeTimers();
        const fetchMock = fetch as ReturnType<typeof vi.fn>;
        fetchMock.mockResolvedValueOnce(jsonResponse(202, { status: 'pending', job_uuid: 'abc' }));

        const { result } = renderHook(() => useTourOptimization(7));
        await addTwoStops(result);
        await act(async () => {
            await result.current.optimize('trucking');
        });

        // next fetch = status poll → done
        fetchMock.mockResolvedValueOnce(jsonResponse(200, { status: 'done', data: RESULT }));
        await act(async () => {
            await vi.advanceTimersByTimeAsync(3000);
        });

        expect(result.current.state).toEqual({ status: 'done', result: RESULT, mode: 'trucking' });
    });

    it('reset clears stops and returns to idle', async () => {
        const { result } = renderHook(() => useTourOptimization(7));
        await addTwoStops(result);

        act(() => result.current.reset());

        expect(result.current.stops).toHaveLength(0);
        expect(result.current.state.status).toBe('idle');
    });

    it('sends the selected mode in the request body and carries it into the done state (003)', async () => {
        (fetch as ReturnType<typeof vi.fn>).mockResolvedValueOnce(
            jsonResponse(200, { status: 'done', data: RESULT }),
        );
        const { result } = renderHook(() => useTourOptimization(7));
        await addTwoStops(result);

        await act(async () => {
            await result.current.optimize('walking');
        });

        const [, options] = (fetch as ReturnType<typeof vi.fn>).mock.calls[0];
        expect(JSON.parse((options as RequestInit).body as string).mode).toBe('walking');
        expect(result.current.state).toEqual({ status: 'done', result: RESULT, mode: 'walking' });
    });
});
