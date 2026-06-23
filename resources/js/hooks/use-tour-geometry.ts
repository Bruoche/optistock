// Feature 002 — road-accurate route tracing. Given the done optimization result,
// fetches the road geometry once and exposes the best-available path: straight-line
// fallback first, then the road-following path. A result-identity token guards
// against a superseded tour's late response winning (FR-010).
import { useEffect, useRef, useState } from 'react';
import { postJson } from '@/lib/http';
import type {
    DeliveryMode,
    RoutePath,
    TourGeometry,
    TourResult,
} from '@/types/tour';

type ComposedGeometry = {
    /** Best-available path for RouteLayer. */
    routePath: RoutePath;
    /** Straight fallback expects RouteLayer to close the loop; road geometry already includes the return leg. */
    closed: boolean;
    /** Road-accurate totals when available, else null (keep the initial estimate). */
    metrics: { distance_m: number | null; duration_s: number | null } | null;
};

function orderedStops(result: TourResult): RoutePath {
    return [...result.ordered_stops]
        .sort((a, b) => a.order - b.order)
        .map(({ lat, lng }) => ({ lat, lng }));
}

function composeGeometry(
    result: TourResult | null,
    geometry: TourGeometry | null,
    loop: boolean,
): ComposedGeometry {
    if (!result) {
        return { routePath: [], closed: true, metrics: null };
    }

    const stops = orderedStops(result);

    // No geometry yet (or fetch failed) → straight fallback; closed only for a looped tour.
    if (!geometry) {
        return { routePath: stops, closed: loop, metrics: null };
    }

    const routePath: RoutePath = [];
    geometry.legs.forEach((leg, index) => {
        if (leg.ok) {
            leg.coordinates.forEach(([lat, lng]) =>
                routePath.push({ lat, lng }),
            );
        } else {
            routePath.push(stops[index], stops[(index + 1) % stops.length]);
        }
    });

    return {
        routePath,
        closed: false,
        metrics: {
            distance_m: geometry.total_distance_m,
            duration_s: geometry.total_duration_s,
        },
    };
}

export function useTourGeometry(
    result: TourResult | null,
    mode: DeliveryMode,
    loop: boolean,
): ComposedGeometry {
    // Geometry stored with the result it was fetched for, so a stale entry is ignored by identity.
    const [entry, setEntry] = useState<{
        result: TourResult;
        geometry: TourGeometry;
    } | null>(null);
    const token = useRef(0);

    useEffect(() => {
        const current = ++token.current;

        if (!result) {
            return;
        }

        const stops = orderedStops(result).map(({ lat, lng }) => [lat, lng]);

        (async () => {
            try {
                // Same mode + loop the tour was optimized with, so the drawn route matches (FR-007).
                const response = await postJson('/api/tour/geometry', {
                    stops,
                    mode,
                    loop,
                });

                if (!response.ok) {
                    return;
                }

                const data = (await response.json()) as TourGeometry;

                // Ignore a response for a superseded tour (FR-010).
                if (current === token.current) {
                    setEntry({ result, geometry: data });
                }
            } catch {
                // Swallow: a failed trace keeps the straight fallback (FR-005).
            }
        })();
    }, [result, mode, loop]);

    const geometry = entry && entry.result === result ? entry.geometry : null;

    return composeGeometry(result, geometry, loop);
}
