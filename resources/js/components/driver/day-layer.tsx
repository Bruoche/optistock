// Draws a driver's planned day (feature 025): every assigned tour as a solid line and
// every connecting drive as a dotted one. With no tour selected the whole day is neutral
// and lightly dimmed (75% opacity); when a tour is selected, its tour leg and the two
// connection legs bracketing it render in the primary color at full opacity, the rest dim
// to 50%, and the highlighted legs draw last so they are never painted over.
//
// Adapted from workday-layer: there the highlight is a server flag on the candidate's
// brackets; here it is derived on the client from the selected tour's index.
import { Layer, Source } from 'react-map-gl/maplibre';
import type { WorkdayLeg } from '@/types/tour';

const NEUTRAL_FALLBACK = '#1a1a1a';
const PRIMARY_FALLBACK = '#ff9a3c';

const DASH_DOTTED = [0.5, 2];
const DASH_SOLID = [1, 0];

function roleColor(variable: string, fallback: string): string {
    if (typeof window === 'undefined') {
        return fallback;
    }

    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(variable)
        .trim();

    return value || fallback;
}

// The legs of a day are [conn, tour0, conn, tour1, …, tourN-1, conn]. The k-th tour
// leg sits at 2k+1; its bracketing connections at 2k and 2k+2. Highlighting a selected
// tour therefore lights those three leg positions.
function highlightedLegs(selectedTourIndex: number | null): Set<number> {
    if (selectedTourIndex === null) {
        return new Set();
    }

    const tourLeg = selectedTourIndex * 2 + 1;

    return new Set([tourLeg - 1, tourLeg, tourLeg + 1]);
}

type DayLayerProps = {
    legs: WorkdayLeg[];
    /** Index into the day's tours of the selected tour, or null when none is selected. */
    selectedTourIndex?: number | null;
};

export function DayLayer({ legs, selectedTourIndex = null }: DayLayerProps) {
    const neutral = roleColor('--route-neutral', NEUTRAL_FALLBACK);
    const primary = roleColor('--primary', PRIMARY_FALLBACK);
    const highlighted = highlightedLegs(selectedTourIndex);
    const hasSelection = selectedTourIndex !== null;

    // Draw non-highlighted legs first and highlighted ones last (stable within each group)
    // so the selected tour stays on top — the ordering the removed `beforeId` used to give.
    const ordered = legs
        .map((leg, index) => ({ leg, index }))
        .sort(
            (a, b) =>
                Number(highlighted.has(a.index)) -
                Number(highlighted.has(b.index)),
        );

    return (
        <>
            {ordered.map(({ leg, index }) => {
                const points = leg.geometry ?? leg.path;

                if (points.length < 2) {
                    return null;
                }

                const isHighlighted = highlighted.has(index);

                const geojson: GeoJSON.Feature<GeoJSON.LineString> = {
                    type: 'Feature',
                    properties: {},
                    geometry: {
                        type: 'LineString',
                        coordinates: points.map(([lat, lng]) => [lng, lat]),
                    },
                };

                // The kind is part of the id so a slot never morphs connection↔tour in
                // place (a morph re-styles a live layer and pops between dashed/solid).
                const legId = `day-${leg.kind}-${index}`;

                return (
                    <Source
                        key={legId}
                        id={legId}
                        type="geojson"
                        data={geojson}
                    >
                        <Layer
                            id={`${legId}-line`}
                            type="line"
                            layout={{
                                'line-cap': 'round',
                                'line-join': 'round',
                            }}
                            paint={{
                                'line-color': isHighlighted ? primary : neutral,
                                'line-width': 4,
                                'line-opacity': isHighlighted
                                    ? 1
                                    : hasSelection
                                      ? 0.5
                                      : 0.75,
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
