// Draws the neutral pieces of a selected driver's projected workday: the
// already-assigned tours and the connection drives around them. The candidate
// tour is not drawn here — RouteLayer keeps it in the primary color, so this
// layer must mount before it to stay underneath.
import { Layer, Source } from 'react-map-gl/maplibre';
import type { WorkdayLeg } from '@/types/tour';

// Used only if the CSS var can't be read (SSR / before styles apply).
const NEUTRAL_FALLBACK = '#1a1a1a';

// MapLibre paint properties can't reference CSS classes/vars, so resolve the
// route-neutral role color at runtime — keeps the palette single-source
// (Constitution VI), same pattern as RouteLayer.
function neutralColor(): string {
    if (typeof window === 'undefined') {
        return NEUTRAL_FALLBACK;
    }

    const value = getComputedStyle(document.documentElement)
        .getPropertyValue('--route-neutral')
        .trim();

    return value || NEUTRAL_FALLBACK;
}

type WorkdayLayerProps = {
    legs: WorkdayLeg[];
};

export function WorkdayLayer({ legs }: WorkdayLayerProps) {
    const color = neutralColor();

    return (
        <>
            {legs.map((leg, index) => {
                const points = leg.geometry ?? leg.path;

                if (points.length < 2) {
                    return null;
                }

                const geojson: GeoJSON.Feature<GeoJSON.LineString> = {
                    type: 'Feature',
                    properties: {},
                    geometry: {
                        type: 'LineString',
                        coordinates: points.map(([lat, lng]) => [lng, lat]),
                    },
                };

                return (
                    <Source
                        key={index}
                        id={`workday-leg-${index}`}
                        type="geojson"
                        data={geojson}
                    >
                        <Layer
                            id={`workday-leg-line-${index}`}
                            type="line"
                            layout={{
                                'line-cap': 'round',
                                'line-join': 'round',
                            }}
                            paint={{
                                'line-color': color,
                                'line-width': 3,
                                ...(leg.dotted
                                    ? { 'line-dasharray': [0.5, 2] }
                                    : {}),
                            }}
                        />
                    </Source>
                );
            })}
        </>
    );
}
