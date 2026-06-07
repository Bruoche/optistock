// Interactive map (MapLibre GL via react-map-gl). Click to drop a stop; existing
// stops render as numbered markers. Overlays (e.g. RouteLayer) are passed as
// children so they draw inside the same map context.
import 'maplibre-gl/dist/maplibre-gl.css';

import { useCallback } from 'react';
import type { ReactNode } from 'react';
import { Map, Marker } from 'react-map-gl/maplibre';
import type { MapLayerMouseEvent, StyleSpecification } from 'react-map-gl/maplibre';
import type { Stop } from '@/types/tour';

// Raster OSM style — no API token required.
const OSM_STYLE: StyleSpecification = {
    version: 8,
    sources: {
        osm: {
            type: 'raster',
            tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
            tileSize: 256,
            attribution: '© OpenStreetMap contributors',
        },
    },
    layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
};

type TourMapProps = {
    stops: Stop[];
    /** Called with the clicked coordinate when the map is addable. */
    onAddStop?: (lat: number, lng: number) => void;
    /** When false, clicks no longer add stops (e.g. while optimizing / after result). */
    addable?: boolean;
    children?: ReactNode;
};

export function TourMap({ stops, onAddStop, addable = true, children }: TourMapProps) {
    const handleClick = useCallback(
        (event: MapLayerMouseEvent) => {
            if (!addable || !onAddStop) {
                return;
            }

            const { lat, lng } = event.lngLat;
            onAddStop(lat, lng);
        },
        [addable, onAddStop],
    );

    return (
        <Map
            initialViewState={{ longitude: 2.3522, latitude: 48.8566, zoom: 11 }}
            mapStyle={OSM_STYLE}
            style={{ width: '100%', height: '100%' }}
            cursor={addable ? 'crosshair' : 'grab'}
            onClick={handleClick}
        >
            {stops.map((stop, index) => (
                <Marker key={stop.id} longitude={stop.lng} latitude={stop.lat} anchor="bottom">
                    <span className="flex size-6 items-center justify-center rounded-full bg-primary text-xs font-semibold text-text-on-color shadow">
                        {index + 1}
                    </span>
                </Marker>
            ))}
            {children}
        </Map>
    );
}
