// FR-019 isolation boundary: draws the optimized route from an ordered list of
// coordinates. Today it renders straight-line segments. To switch to
// road-accurate geometry later (deferred /api/route/), only this file changes —
// the page/list keep passing the same `path`.
import { Layer, Source } from 'react-map-gl/maplibre';
import type { RoutePath } from '@/types/tour';

type RouteLayerProps = {
    path: RoutePath;
    /** Draw a closed loop back to the first stop (tour=closed). Defaults to true. */
    closed?: boolean;
};

// Used only if the CSS var can't be read (SSR / before styles apply).
const PRIMARY_FALLBACK = '#ff9a3c';

// MapLibre paint properties can't reference CSS classes/vars, so resolve the
// primary role color at runtime — keeps the palette single-source (Constitution VI).
function primaryColor(): string {
    if (typeof window === 'undefined') {
        return PRIMARY_FALLBACK;
    }

    const value = getComputedStyle(document.documentElement)
        .getPropertyValue('--primary')
        .trim();

    return value || PRIMARY_FALLBACK;
}

/** The candidate tour's MapLibre layer id — overlays anchor below it via beforeId. */
export const TOUR_ROUTE_LAYER_ID = 'tour-route-line';

export function RouteLayer({ path, closed = true }: RouteLayerProps) {
    if (path.length < 2) {
        return null;
    }

    const coordinates = path.map(({ lat, lng }) => [lng, lat]);

    if (closed) {
        coordinates.push(coordinates[0]);
    }

    const geojson: GeoJSON.Feature<GeoJSON.LineString> = {
        type: 'Feature',
        properties: {},
        geometry: { type: 'LineString', coordinates },
    };

    return (
        <Source id="tour-route" type="geojson" data={geojson}>
            <Layer
                id={TOUR_ROUTE_LAYER_ID}
                type="line"
                layout={{ 'line-cap': 'round', 'line-join': 'round' }}
                paint={{ 'line-color': primaryColor(), 'line-width': 4 }}
            />
        </Source>
    );
}
