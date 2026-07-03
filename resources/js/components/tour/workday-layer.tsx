// Draws the neutral pieces of a selected driver's projected workday: the
// already-assigned tours and the connection drives around them. The candidate
// tour is not drawn here — RouteLayer keeps it in the primary color; every leg
// layer anchors below it via beforeId (MapLibre otherwise appends remounted
// layers on top, regardless of JSX order).
import { Layer, Source } from 'react-map-gl/maplibre';
import { TOUR_ROUTE_LAYER_ID } from '@/components/tour/route-layer';
import type { WorkdayLeg } from '@/types/tour';

// Used only if the CSS var can't be read (SSR / before styles apply).
const NEUTRAL_FALLBACK = '#1a1a1a';

// Dash patterns are set explicitly on every leg (a solid line is dash [1, 0]):
// conditionally omitting line-dasharray leaves its reset to paint diffing, which
// flashes the previous pattern when a layer updates.
const DASH_DOTTED = [0.5, 2];
const DASH_SOLID = [1, 0];

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

                // The kind is part of the identity so a slot never morphs from
                // connection to tour in place — a morph re-styles an existing
                // MapLibre layer and pops between dashed and solid mid-frame.
                const legId = `workday-${leg.kind}-${index}`;

                return (
                    <Source
                        key={legId}
                        id={legId}
                        type="geojson"
                        data={geojson}
                    >
                        <Layer
                            id={`${legId}-line`}
                            beforeId={TOUR_ROUTE_LAYER_ID}
                            type="line"
                            layout={{
                                'line-cap': 'round',
                                'line-join': 'round',
                            }}
                            paint={{
                                'line-color': color,
                                'line-width': 3,
                                'line-dasharray': leg.dotted
                                    ? DASH_DOTTED
                                    : DASH_SOLID,
                            }}
                        />
                    </Source>
                );
            })}
        </>
    );
}
