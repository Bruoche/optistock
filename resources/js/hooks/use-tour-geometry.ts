// Feature 002 — road-accurate route tracing.
// Given the done optimization result, fetches the road geometry once (synchronous
// backend call) and exposes the best-available path: straight-line fallback first,
// then the road-following path once it arrives. Pure enhancement — a failed fetch
// leaves the straight fallback + null road metrics (the caller keeps the initial
// estimate). Owns a result-identity token so a superseded tour's late response is
// ignored. Deliberately separate from use-tour-optimization (no job concepts here).
import { useEffect, useRef, useState } from 'react';
import { postJson } from '@/lib/http';
import type { RoutePath, TourGeometry, TourResult } from '@/types/tour';

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

function composeGeometry(result: TourResult | null, geometry: TourGeometry | null): ComposedGeometry {
    if (!result) {
        return { routePath: [], closed: true, metrics: null };
    }

    const stops = orderedStops(result);

    // No geometry yet (or fetch failed) → straight fallback; RouteLayer closes the loop.
    if (!geometry) {
        return { routePath: stops, closed: true, metrics: null };
    }

    // Road path: per leg, use road coords where the leg succeeded, else a straight segment.
    const routePath: RoutePath = [];
    geometry.legs.forEach((leg, index) => {
        if (leg.ok) {
            leg.coordinates.forEach(([lat, lng]) => routePath.push({ lat, lng }));
        } else {
            routePath.push(stops[index], stops[(index + 1) % stops.length]);
        }
    });

    return {
        routePath,
        closed: false, // legs already include the return leg
        metrics: { distance_m: geometry.total_distance_m, duration_s: geometry.total_duration_s },
    };
}

export function useTourGeometry(result: TourResult | null): ComposedGeometry {
    // Store the geometry together with the result it was fetched for, so a stale
    // entry (from a previous tour) is simply ignored by identity comparison — no
    // need to reset state synchronously inside the effect.
    const [entry, setEntry] = useState<{ result: TourResult; geometry: TourGeometry } | null>(null);
    const token = useRef(0);

    useEffect(() => {
        const current = ++token.current;

        if (!result) {
            return;
        }

        const stops = orderedStops(result).map(({ lat, lng }) => [lat, lng]);

        (async () => {
            try {
                const response = await postJson('/api/tour/geometry', { stops });

                if (!response.ok) {
                    return; // keep straight fallback (FR-005)
                }

                const data = (await response.json()) as TourGeometry;

                // Ignore a response for a superseded tour (FR-010).
                if (current === token.current) {
                    setEntry({ result, geometry: data });
                }
            } catch {
                // network error — keep straight fallback
            }
        })();
    }, [result]);

    // Only use geometry that belongs to the current result.
    const geometry = entry && entry.result === result ? entry.geometry : null;

    return composeGeometry(result, geometry);
}
