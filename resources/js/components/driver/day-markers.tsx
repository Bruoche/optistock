// Point markers for a driver's planned day (feature 025): the warehouse (day start/end)
// and a "T{n}" marker at the entry point of each tour, numbered in running order. Mirrors
// the numbered stop markers' circle, in the neutral role — adapted from workday-markers,
// which draws a warehouse + a single "0" origin.
import { Building2 } from 'lucide-react';
import { Marker } from 'react-map-gl/maplibre';
import type { DayTour } from '@/types/driver';
import type { ReactNode } from 'react';

function PointMarker({
    coordinate,
    children,
}: {
    coordinate: [number, number];
    children: ReactNode;
}) {
    return (
        <Marker
            longitude={coordinate[1]}
            latitude={coordinate[0]}
            anchor="bottom"
        >
            <span className="flex size-6 items-center justify-center rounded-full bg-route-neutral/50 text-xs font-semibold text-route-neutral-foreground shadow">
                {children}
            </span>
        </Marker>
    );
}

type DayMarkersProps = {
    warehouseCoordinate: [number, number];
    tours: DayTour[];
};

export function DayMarkers({ warehouseCoordinate, tours }: DayMarkersProps) {
    return (
        <>
            <PointMarker coordinate={warehouseCoordinate}>
                <Building2 className="size-3.5" />
            </PointMarker>
            {tours.map((tour, index) => (
                <PointMarker key={tour.id} coordinate={tour.startCoordinate}>
                    T{index + 1}
                </PointMarker>
            ))}
        </>
    );
}
