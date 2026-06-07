// Owns the tour-optimization flow: stop list + the OptimizeState machine.
// idle → submitting → (done | pending) → done | failed → idle (reset).
// On a 202 it subscribes to the user's private Reverb channel and also polls the
// status endpoint as a WebSocket fallback, so the UI is never stuck on `pending`.
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { getEcho } from '@/lib/echo';
import type { OptimizeState, Stop, TourError, TourResult } from '@/types/tour';

const POLL_INTERVAL_MS = 3000;

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(^|;\\s*)' + name + '=([^;]*)'));

    return match ? decodeURIComponent(match[2]) : null;
}

async function postJson(url: string, body: unknown): Promise<Response> {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') ?? '',
        },
        body: JSON.stringify(body),
    });
}

export function useTourOptimization(userId: number) {
    const [stops, setStops] = useState<Stop[]>([]);
    const [state, setState] = useState<OptimizeState>({ status: 'idle' });

    const channelName = `App.Models.User.${userId}`;
    const pollTimer = useRef<ReturnType<typeof setInterval> | null>(null);
    const activeJob = useRef<string | null>(null);

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
            setState({ status: 'done', result });
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
        setStops((current) => [...current, { id: crypto.randomUUID(), lat, lng }]);
    }, []);

    const removeStop = useCallback((id: string) => {
        setStops((current) => current.filter((stop) => stop.id !== id));
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
                .listen('.TourOptimized', (event: { job_uuid: string; data: TourResult }) => {
                    if (event.job_uuid === activeJob.current) {
                        settleDone(event.data);
                    }
                })
                .listen('.TourOptimizationFailed', (event: { job_uuid: string; error: TourError }) => {
                    if (event.job_uuid === activeJob.current) {
                        settleFailed(event.error);
                    }
                });

            // WebSocket fallback: poll the status endpoint.
            pollTimer.current = setInterval(async () => {
                try {
                    const response = await fetch(`/api/tour/status/${jobUuid}`, {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    });

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

    const optimize = useCallback(async () => {
        if (stops.length < 2) {
            return;
        }

        setState({ status: 'submitting' });

        try {
            const response = await postJson('/api/tour/optimize', {
                coordinates: stops.map((stop) => [stop.lat, stop.lng]),
            });

            if (response.status === 200) {
                const payload = await response.json();
                setState({ status: 'done', result: payload.data as TourResult });

                return;
            }

            if (response.status === 202) {
                const payload = await response.json();
                setState({ status: 'pending', jobUuid: payload.job_uuid });
                subscribe(payload.job_uuid);

                return;
            }

            if (response.status === 422) {
                settleFailed({ code: 'invalid_response', message: 'Some coordinates are invalid.' });

                return;
            }

            if (response.status === 429) {
                settleFailed({ code: 'api_error', message: 'Too many requests — please wait a minute.' });

                return;
            }

            settleFailed({ code: 'api_error', message: 'Could not start optimization.' });
        } catch {
            settleFailed({ code: 'timeout', message: 'Network error — please try again.' });
        }
    }, [stops, subscribe, settleFailed]);

    // Drop subscriptions/timers if the component unmounts mid-flight.
    useEffect(() => cleanup, [cleanup]);

    return { stops, addStop, removeStop, optimize, reset, state };
}
