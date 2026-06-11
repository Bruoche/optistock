// Owns the tour-optimization flow: stop list + the OptimizeState machine.
// idle → submitting → (done | pending) → done | failed → idle (reset).
// On a 202 it subscribes to the user's private Reverb channel and also polls the
// status endpoint as a WebSocket fallback, so the UI is never stuck on `pending`.
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { getEcho } from '@/lib/echo';
import { postJson } from '@/lib/http';
import {
    DEFAULT_STOP_DURATION_MINUTES,
    MAX_STOP_DURATION_MINUTES,
} from '@/types/tour';
import type {
    DeliveryMode,
    OptimizeState,
    Stop,
    TourError,
    TourResult,
} from '@/types/tour';

const POLL_INTERVAL_MS = 3000;

// Keep a stop duration a valid non-negative whole number (CR-2): empty/NaN/negative
// reads as 0 ("no time here"), non-integers floor, and the 24 h ceiling clamps.
function normalizeStopDuration(minutes: number): number {
    if (!Number.isFinite(minutes) || minutes < 0) {
        return 0;
    }

    return Math.min(Math.floor(minutes), MAX_STOP_DURATION_MINUTES);
}

export function useTourOptimization(userId: number) {
    const [stops, setStops] = useState<Stop[]>([]);
    const [state, setState] = useState<OptimizeState>({ status: 'idle' });

    const channelName = `App.Models.User.${userId}`;
    const pollTimer = useRef<ReturnType<typeof setInterval> | null>(null);
    const activeJob = useRef<string | null>(null);
    // Snapshot of the shape a pending optimization was started with, so a result
    // arriving via broadcast/poll is settled with the values it was optimized for (FR-007).
    const optimizedMode = useRef<DeliveryMode>('trucking');
    const closeLoop = useRef<boolean>(true);

    const cleanup = useCallback(() => {
        if (pollTimer.current) {
            clearInterval(pollTimer.current);
            pollTimer.current = null;
        }

        activeJob.current = null;
        getEcho().leave(channelName);
    }, [channelName]);

    const settleDone = useCallback(
        (result: TourResult) => {
            cleanup();
            setState({
                status: 'done',
                result,
                mode: optimizedMode.current,
                loop: closeLoop.current,
            });
        },
        [cleanup],
    );

    const settleFailed = useCallback(
        (error: TourError) => {
            cleanup();
            setState({ status: 'failed', error });
            toast.error(error.message || 'Optimization failed.');
        },
        [cleanup],
    );

    const addStop = useCallback((lat: number, lng: number) => {
        setStops((current) => [
            ...current,
            {
                id: crypto.randomUUID(),
                lat,
                lng,
                durationMinutes: DEFAULT_STOP_DURATION_MINUTES,
            },
        ]);
    }, []);

    const removeStop = useCallback((id: string) => {
        setStops((current) => current.filter((stop) => stop.id !== id));
    }, []);

    const setStopDuration = useCallback((id: string, minutes: number) => {
        setStops((current) =>
            current.map((stop) =>
                stop.id === id
                    ? {
                          ...stop,
                          durationMinutes: normalizeStopDuration(minutes),
                      }
                    : stop,
            ),
        );
    }, []);

    const reset = useCallback(() => {
        cleanup();
        setStops([]);
        setState({ status: 'idle' });
    }, [cleanup]);

    const subscribe = useCallback(
        (jobUuid: string) => {
            activeJob.current = jobUuid;

            getEcho()
                .private(channelName)
                .listen(
                    '.TourOptimized',
                    (event: { job_uuid: string; data: TourResult }) => {
                        if (event.job_uuid === activeJob.current) {
                            settleDone(event.data);
                        }
                    },
                )
                .listen(
                    '.TourOptimizationFailed',
                    (event: { job_uuid: string; error: TourError }) => {
                        if (event.job_uuid === activeJob.current) {
                            settleFailed(event.error);
                        }
                    },
                );

            // WebSocket fallback: poll the status endpoint.
            pollTimer.current = setInterval(async () => {
                try {
                    const response = await fetch(
                        `/api/tour/status/${jobUuid}`,
                        {
                            credentials: 'same-origin',
                            headers: { Accept: 'application/json' },
                        },
                    );

                    if (!response.ok) {
                        return;
                    }

                    const payload = await response.json();

                    if (payload.status === 'done') {
                        settleDone(payload.data as TourResult);
                    } else if (payload.status === 'failed') {
                        settleFailed(payload.error as TourError);
                    }
                } catch {
                    // transient — keep polling
                }
            }, POLL_INTERVAL_MS);
        },
        [channelName, settleDone, settleFailed],
    );

    const optimize = useCallback(
        async (mode: DeliveryMode, loop: boolean) => {
            if (stops.length < 2) {
                return;
            }

            optimizedMode.current = mode;
            closeLoop.current = loop;
            setState({ status: 'submitting', mode, loop });

            try {
                const response = await postJson('/api/tour/optimize', {
                    coordinates: stops.map((stop) => [stop.lat, stop.lng]),
                    mode,
                    loop,
                });

                if (response.status === 200) {
                    const payload = await response.json();
                    setState({
                        status: 'done',
                        result: payload.data as TourResult,
                        mode,
                        loop,
                    });

                    return;
                }

                if (response.status === 202) {
                    const payload = await response.json();
                    setState({
                        status: 'pending',
                        jobUuid: payload.job_uuid,
                        mode,
                        loop,
                    });
                    subscribe(payload.job_uuid);

                    return;
                }

                if (response.status === 422) {
                    settleFailed({
                        code: 'invalid_response',
                        message: 'Some coordinates are invalid.',
                    });

                    return;
                }

                if (response.status === 429) {
                    settleFailed({
                        code: 'api_error',
                        message: 'Too many requests — please wait a minute.',
                    });

                    return;
                }

                settleFailed({
                    code: 'api_error',
                    message: 'Could not start optimization.',
                });
            } catch {
                settleFailed({
                    code: 'timeout',
                    message: 'Network error — please try again.',
                });
            }
        },
        [stops, subscribe, settleFailed],
    );

    // Drop subscriptions/timers if the component unmounts mid-flight.
    useEffect(() => cleanup, [cleanup]);

    // Stop total in seconds (FR-007). Derived live: stops are frozen between submit
    // and done, so this stays congruent with the optimized tour without a snapshot.
    const waitTimeS =
        stops.reduce((sum, stop) => sum + stop.durationMinutes, 0) * 60;

    return {
        stops,
        addStop,
        removeStop,
        setStopDuration,
        optimize,
        reset,
        state,
        waitTimeS,
    };
}
