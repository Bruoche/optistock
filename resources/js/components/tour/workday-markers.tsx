// Point markers for a selected driver's projected workday (feature 018): the
// warehouse (day start/end) and, when the driver has a prior tour that day, the
// origin they drive in from (the end of that tour). Both mirror the numbered
// stop markers' circle, in the neutral role at 50% opacity.
import { Building2 } from 'lucide-react';
import { Marker } from 'react-map-gl/maplibre';
import type { Driver } from '@/types/tour';
import type { ReactNode } from 'react';

const CIRCLE_CLASS =
    'flex size-6 items-center justify-center rounded-full bg-route-neutral/50 text-xs font-semibold text-route-neutral-foreground shadow';

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
            <span className={CIRCLE_CLASS}>{children}</span>
        </Marker>
    );
}

export function WorkdayMarkers({ driver }: { driver: Driver }) {
    return (
        <>
            <PointMarker coordinate={driver.warehouseCoordinate}>
                <Building2 className="size-3.5" />
            </PointMarker>
            {driver.previousTourEnd && (
                <PointMarker coordinate={driver.previousTourEnd}>0</PointMarker>
            )}
        </>
    );
}
