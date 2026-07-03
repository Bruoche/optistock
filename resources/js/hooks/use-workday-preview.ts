// Feature 014 — the selected driver's workday legs with their best-available
// geometry. Legs render immediately on their straight fallback paths; each leg
// missing road geometry is traced lazily, and the result replaces the straight
// line in place. Traced paths are keyed by driver, so a result landing after the
// selection changed can never redraw another driver's preview (FR-009) — it only
// warms the cache. The cache lives per driver-list load, so cycling back to a
// driver is instant and fetch-free (FR-010).
import { useEffect, useRef, useState } from 'react';
import { postJson } from '@/lib/http';
import type {
    DeliveryMode,
    Driver,
    TourGeometry,
    WorkdayLeg,
} from '@/types/tour';

type TracedPath = Array<[number, number]>;

// A failed hop keeps its straight segment, mirroring useTourGeometry's composition.
function composeLegPath(leg: WorkdayLeg, geometry: TourGeometry): TracedPath {
    const path: TracedPath = [];
    geometry.legs.forEach((geoLeg, index) => {
        if (geoLeg.ok) {
            path.push(...geoLeg.coordinates);
        } else {
            path.push(leg.path[index], leg.path[(index + 1) % leg.path.length]);
        }
    });

    return path;
}

export function useWorkdayPreview(
    driver: Driver | null,
    mode: DeliveryMode,
    /** Changes when the driver list reloads (new tour/date), keying a fresh cache. */
    cacheKey: string,
): WorkdayLeg[] {
    const [traced, setTraced] = useState<ReadonlyMap<string, TracedPath>>(
        new Map(),
    );
    // Effect-side bookkeeping (never read during render): which legs are already
    // traced or being traced, so re-selecting a driver never refetches.
    const tracedKeys = useRef(new Set<string>());
    const inFlight = useRef(new Set<string>());

    useEffect(() => {
        if (!driver) {
            return;
        }

        driver.legs.forEach(async (leg, index) => {
            if (leg.geometry !== null || leg.path.length < 2) {
                return;
            }

            // cacheKey in the key retires a stale load's entries without any reset.
            const key = `${cacheKey}:${driver.id}:${index}`;

            if (tracedKeys.current.has(key) || inFlight.current.has(key)) {
                return;
            }

            inFlight.current.add(key);

            try {
                // No tour_id: a preview trace must never finalize road totals
                // onto a persisted tour (the 012 side effect of this endpoint).
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
                // Failures are logged server-side by the geometry endpoint; the
                // straight-line fallback simply stays.
            } finally {
                inFlight.current.delete(key);
            }
        });
    }, [driver, mode, cacheKey]);

    if (!driver) {
        return [];
    }

    return driver.legs.map((leg, index) => {
        const roadPath = traced.get(`${cacheKey}:${driver.id}:${index}`);

        return roadPath ? { ...leg, geometry: roadPath } : leg;
    });
}
