import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DayMarkers } from './day-markers';
import type { DayTour } from '@/types/driver';

vi.mock('react-map-gl/maplibre', () => ({
    Marker: ({
        longitude,
        latitude,
        children,
    }: {
        longitude: number;
        latitude: number;
        children: React.ReactNode;
    }) => (
        <div data-testid="marker" data-lng={longitude} data-lat={latitude}>
            {children}
        </div>
    ),
}));

function tour(id: number, start: [number, number]): DayTour {
    return {
        id,
        sequence: id,
        loop: true,
        totalDurationS: 100,
        drivenDurationS: 60,
        stopSeconds: 40,
        startCoordinate: start,
        stops: [],
    };
}

describe('DayMarkers', () => {
    it('draws a warehouse marker plus one T{n} per tour at its start', () => {
        const { container } = render(
            <DayMarkers
                warehouseCoordinate={[48.8, 2.3]}
                tours={[
                    tour(1, [48.85, 2.35]),
                    tour(2, [48.9, 2.4]),
                    tour(3, [48.95, 2.45]),
                ]}
            />,
        );

        const markers = container.querySelectorAll('[data-testid="marker"]');
        expect(markers).toHaveLength(4); // warehouse + T1 + T2 + T3

        // Warehouse first: building glyph, no "T".
        expect(markers[0].getAttribute('data-lat')).toBe('48.8');
        expect(markers[0].querySelector('svg')).not.toBeNull();

        expect(markers[1].textContent).toBe('T1');
        expect(markers[1].getAttribute('data-lat')).toBe('48.85');
        expect(markers[2].textContent).toBe('T2');
        expect(markers[3].textContent).toBe('T3');
    });

    it('draws only the warehouse marker for an empty day', () => {
        const { container } = render(
            <DayMarkers warehouseCoordinate={[48.8, 2.3]} tours={[]} />,
        );

        expect(
            container.querySelectorAll('[data-testid="marker"]'),
        ).toHaveLength(1);
    });
});
