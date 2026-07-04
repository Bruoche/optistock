import { render } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { WorkdayMarkers } from './workday-markers';
import type { Driver } from '@/types/tour';

// Marker requires a live MapLibre context; render it as an inspectable stub that
// exposes its position and its children (the circle span).
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

function driver(overrides: Partial<Driver> = {}): Driver {
    return {
        id: 1,
        name: 'Amelie',
        imageUrl: null,
        modes: ['driving'],
        warehouseName: 'North Depot',
        projectedSeconds: 0,
        projectedIncomplete: false,
        timeToTour: null,
        timeFromTour: null,
        startIndex: 0,
        warehouseCoordinate: [48.5, 2.5],
        previousTourEnd: null,
        addedBreak: 0,
        legs: [],
        ...overrides,
    };
}

describe('WorkdayMarkers', () => {
    it('draws only the warehouse marker when there is no prior tour', () => {
        const { container } = render(
            <WorkdayMarkers driver={driver({ previousTourEnd: null })} />,
        );

        const markers = container.querySelectorAll('[data-testid="marker"]');
        expect(markers).toHaveLength(1);

        const warehouse = markers[0];
        expect(warehouse.getAttribute('data-lat')).toBe('48.5');
        expect(warehouse.getAttribute('data-lng')).toBe('2.5');

        const circle = warehouse.querySelector('span');
        expect(circle?.className).toContain('size-6');
        expect(circle?.className).toContain('rounded-full');
        expect(circle?.className).toContain('bg-route-neutral/50');
        expect(circle?.className).toContain('text-route-neutral-foreground');
        // The building glyph renders as an svg (lucide); no "0" text on the warehouse.
        expect(circle?.querySelector('svg')).not.toBeNull();
        expect(warehouse.textContent).not.toContain('0');
    });

    it('adds a "0" origin marker at the prior tour end when there is one', () => {
        const { container } = render(
            <WorkdayMarkers
                driver={driver({ previousTourEnd: [48.71, 2.11] })}
            />,
        );

        const markers = container.querySelectorAll('[data-testid="marker"]');
        expect(markers).toHaveLength(2);

        const origin = markers[1];
        expect(origin.getAttribute('data-lat')).toBe('48.71');
        expect(origin.getAttribute('data-lng')).toBe('2.11');

        const circle = origin.querySelector('span');
        expect(circle?.textContent).toBe('0');
        expect(circle?.className).toContain('bg-route-neutral/50');
        expect(circle?.className).toContain('text-route-neutral-foreground');
    });

    it('shows no "0" marker when the driver departs from the warehouse', () => {
        const { container } = render(
            <WorkdayMarkers driver={driver({ previousTourEnd: null })} />,
        );

        expect(
            container.querySelectorAll('[data-testid="marker"]'),
        ).toHaveLength(1);
    });
});
