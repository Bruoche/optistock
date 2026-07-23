import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DayLayer } from './day-layer';
import type { WorkdayLeg } from '@/types/tour';

// MapLibre paint isn't evaluated in jsdom — expose each leg's resolved color + opacity
// as data attributes so we can assert the selection highlight at the prop layer.
vi.mock('react-map-gl/maplibre', () => ({
    Source: ({ children }: { children: React.ReactNode }) => <>{children}</>,
    Layer: ({ paint }: { paint: Record<string, unknown> }) => (
        <div
            data-testid="layer"
            data-color={String(paint['line-color'])}
            data-opacity={String(paint['line-opacity'])}
        />
    ),
}));

const NEUTRAL = '#1a1a1a';
const PRIMARY = '#ff9a3c';

function connection(): WorkdayLeg {
    return {
        kind: 'connection',
        dotted: true,
        path: [
            [48.8, 2.3],
            [48.85, 2.35],
        ],
        geometry: null,
        loop: false,
        highlight: false,
    };
}

function tour(): WorkdayLeg {
    return {
        kind: 'tour',
        dotted: false,
        path: [
            [48.85, 2.35],
            [48.86, 2.36],
        ],
        geometry: null,
        loop: true,
        highlight: false,
    };
}

// A two-tour day: [conn, tour0, conn, tour1, conn].
const legs = [connection(), tour(), connection(), tour(), connection()];

describe('DayLayer', () => {
    it('draws every leg neutral when nothing is selected', () => {
        render(<DayLayer legs={legs} selectedTourIndex={null} />);

        const layers = screen.getAllByTestId('layer');
        expect(layers).toHaveLength(5);
        layers.forEach((layer) => {
            expect(layer.getAttribute('data-color')).toBe(NEUTRAL);
            expect(layer.getAttribute('data-opacity')).toBe('1');
        });
    });

    it('highlights the selected tour leg + its two bracketing connections, dimming the rest', () => {
        render(<DayLayer legs={legs} selectedTourIndex={0} />);

        const layers = screen.getAllByTestId('layer');
        // Tour 0 → legs 0 (conn), 1 (tour), 2 (conn) highlighted primary/full opacity.
        [0, 1, 2].forEach((i) => {
            expect(layers[i].getAttribute('data-color')).toBe(PRIMARY);
            expect(layers[i].getAttribute('data-opacity')).toBe('1');
        });
        // The rest dim to the neutral role at 50%.
        [3, 4].forEach((i) => {
            expect(layers[i].getAttribute('data-color')).toBe(NEUTRAL);
            expect(layers[i].getAttribute('data-opacity')).toBe('0.5');
        });
    });
});
