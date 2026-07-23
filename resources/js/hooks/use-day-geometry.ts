// Feature 025 — the day's legs with their best-available geometry. Legs render
// immediately on their straight fallback; each leg missing road geometry is traced
// lazily via the frozen geometry endpoint (no tour_id, so no persistence side effect),
// and the result replaces the straight line in place. Traces are keyed per day-load, so
// a result landing after the day changed only warms the cache — it never redraws a stale
// day (FR-039). Mirrors use-workday-preview; the input is a leg list, not a driver.
import { useEffect, useRef, useState } from 'react';
import { postJson } from '@/lib/http';
import type { DeliveryMode, TourGeometry, WorkdayLeg } from '@/types/tour';

type TracedPath = Array<[number, number]>;

function composeLegPath(leg: WorkdayLeg, geometry: TourGeometry): TracedPath {
    const path: TracedPath = [];
    geometry.legs.forEach((geoLeg, index) => {
        if (geoLeg.ok) {
            path.push(...geoLeg.coordinates);

            return;
        }

        const from = leg.path[index];
        const to = leg.path[(index + 1) % leg.path.length];

        if (from && to) {
            path.push(from, to);
        }
    });

    return path;
}

function allPointsCoincide(path: TracedPath): boolean {
    const [[firstLat, firstLng]] = path;

    return path.every(([lat, lng]) => lat === firstLat && lng === firstLng);
}

export function useDayGeometry(
    legs: WorkdayLeg[],
    mode: DeliveryMode | null,
    /** Changes when the day reloads (new driver/date), keying a fresh cache. */
    cacheKey: string,
): WorkdayLeg[] {
    const [traced, setTraced] = useState<ReadonlyMap<string, TracedPath>>(
        new Map(),
    );
    const tracedKeys = useRef(new Set<string>());
    const inFlight = useRef(new Set<string>());

    useEffect(() => {
        if (mode === null) {
            return;
        }

        legs.forEach(async (leg, index) => {
            if (
                leg.geometry !== null ||
                leg.path.length < 2 ||
                allPointsCoincide(leg.path)
            ) {
                return;
            }

            const key = `${cacheKey}:${index}`;

            if (tracedKeys.current.has(key) || inFlight.current.has(key)) {
                return;
            }

            inFlight.current.add(key);

            try {
                const response = await postJson('/api/tour/geometry', {
                    stops: leg.path,
                    mode,
                    loop: leg.loop,
                });

                if (!response.ok) {
                    return;
                }

                const geometry = (await response.json()) as TourGeometry;
                tracedKeys.current.add(key);
                setTraced((previous) =>
                    new Map(previous).set(key, composeLegPath(leg, geometry)),
                );
            } catch {
                // Geometry failures are logged server-side; the straight fallback stays.
            } finally {
                inFlight.current.delete(key);
            }
        });
    }, [legs, mode, cacheKey]);

    return legs.map((leg, index) => {
        const roadPath = traced.get(`${cacheKey}:${index}`);

        return roadPath ? { ...leg, geometry: roadPath } : leg;
    });
}
