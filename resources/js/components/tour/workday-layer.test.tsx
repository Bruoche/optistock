import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { WorkdayLayer } from './workday-layer';
import type { WorkdayLeg } from '@/types/tour';

// Source/Layer require a live MapLibre context; render them as inspectable stubs.
vi.mock('react-map-gl/maplibre', () => ({
    Source: ({
        id,
        data,
        children,
    }: {
        id: string;
        data: GeoJSON.Feature<GeoJSON.LineString>;
        children: React.ReactNode;
    }) => (
        <div
            data-testid="source"
            data-id={id}
            data-coordinates={JSON.stringify(data.geometry.coordinates)}
        >
            {children}
        </div>
    ),
    Layer: (props: Record<string, unknown>) => (
        <div
            data-testid="layer"
            data-paint={JSON.stringify(props.paint)}
            data-before-id={String(props.beforeId)}
        />
    ),
}));

function leg(overrides: Partial<WorkdayLeg> = {}): WorkdayLeg {
    return {
        kind: 'connection',
        dotted: true,
        path: [
            [48.9, 2.3],
            [48.85, 2.35],
        ],
        geometry: null,
        loop: false,
        ...overrides,
    };
}

function renderedCoordinates(container: HTMLElement): number[][][] {
    return Array.from(container.querySelectorAll('[data-testid="source"]')).map(
        (source) => JSON.parse(source.getAttribute('data-coordinates') ?? '[]'),
    );
}

describe('WorkdayLayer', () => {
    beforeEach(() => {
        document.documentElement.style.setProperty(
            '--route-neutral',
            '#1a1a1a',
        );
    });

    it('renders one line per leg', () => {
        const { container } = render(
            <WorkdayLayer
                legs={[leg(), leg({ kind: 'tour', dotted: false })]}
            />,
        );

        expect(
            container.querySelectorAll('[data-testid="source"]'),
        ).toHaveLength(2);
    });

    it('draws the straight path (as lng,lat) when a leg has no geometry', () => {
        const { container } = render(<WorkdayLayer legs={[leg()]} />);

        expect(renderedCoordinates(container)[0]).toEqual([
            [2.3, 48.9],
            [2.35, 48.85],
        ]);
    });

    it('draws the road geometry when the leg has one', () => {
        const withGeometry = leg({
            geometry: [
                [48.9, 2.3],
                [48.88, 2.31],
                [48.85, 2.35],
            ],
        });

        const { container } = render(<WorkdayLayer legs={[withGeometry]} />);

        expect(renderedCoordinates(container)[0]).toEqual([
            [2.3, 48.9],
            [2.31, 48.88],
            [2.35, 48.85],
        ]);
    });

    it('paints every leg in the route-neutral role color', () => {
        const { container } = render(
            <WorkdayLayer
                legs={[leg(), leg({ kind: 'tour', dotted: false })]}
            />,
        );

        const paints = Array.from(
            container.querySelectorAll('[data-testid="layer"]'),
        ).map((layer) => JSON.parse(layer.getAttribute('data-paint') ?? '{}'));
        expect(paints).toHaveLength(2);
        paints.forEach((paint) => {
            expect(paint['line-color']).toBe('#1a1a1a');
        });
    });

    it('dashes connection legs and keeps tour legs solid, always explicitly', () => {
        const { container } = render(
            <WorkdayLayer
                legs={[
                    leg({ kind: 'connection', dotted: true }),
                    leg({ kind: 'tour', dotted: false }),
                ]}
            />,
        );

        const paints = Array.from(
            container.querySelectorAll('[data-testid="layer"]'),
        ).map((layer) => JSON.parse(layer.getAttribute('data-paint') ?? '{}'));
        expect(paints[0]['line-dasharray']).toEqual([0.5, 2]);
        // Solid is an explicit dash too — omitting the property would leave its
        // reset to paint diffing and pop patterns on layer updates.
        expect(paints[1]['line-dasharray']).toEqual([1, 0]);
    });

    it('keys each leg by its kind and anchors it below the candidate route layer', () => {
        const { container } = render(
            <WorkdayLayer
                legs={[
                    leg({ kind: 'connection', dotted: true }),
                    leg({ kind: 'tour', dotted: false }),
                ]}
            />,
        );

        const sourceIds = Array.from(
            container.querySelectorAll('[data-testid="source"]'),
        ).map((source) => source.getAttribute('data-id'));
        expect(sourceIds).toEqual(['workday-connection-0', 'workday-tour-1']);

        Array.from(container.querySelectorAll('[data-testid="layer"]')).forEach(
            (layer) => {
                expect(layer.getAttribute('data-before-id')).toBe(
                    'tour-route-line',
                );
            },
        );
    });

    it('skips a degenerate leg with fewer than two points', () => {
        const { container } = render(
            <WorkdayLayer legs={[leg({ path: [[48.9, 2.3]] })]} />,
        );

        expect(
            container.querySelectorAll('[data-testid="source"]'),
        ).toHaveLength(0);
    });
});
