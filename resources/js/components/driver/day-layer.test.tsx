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
    it('draws every leg neutral and lightly dimmed (75%) when nothing is selected', () => {
        render(<DayLayer legs={legs} selectedTourIndex={null} />);

        const layers = screen.getAllByTestId('layer');
        expect(layers).toHaveLength(5);
        layers.forEach((layer) => {
            expect(layer.getAttribute('data-color')).toBe(NEUTRAL);
            expect(layer.getAttribute('data-opacity')).toBe('0.75');
        });
    });

    it('highlights the selected tour + its two bracketing connections, dims the rest, and draws the highlighted legs last', () => {
        // Tour 0 → input legs 0 (conn), 1 (tour), 2 (conn) are the highlighted three.
        render(<DayLayer legs={legs} selectedTourIndex={0} />);

        const layers = screen.getAllByTestId('layer');
        const primary = layers.filter(
            (l) => l.getAttribute('data-color') === PRIMARY,
        );
        const neutral = layers.filter(
            (l) => l.getAttribute('data-color') === NEUTRAL,
        );

        // Exactly the three bracketed legs are primary at full opacity.
        expect(primary).toHaveLength(3);
        primary.forEach((l) =>
            expect(l.getAttribute('data-opacity')).toBe('1'),
        );
        // The remaining two dim to the neutral role at 50%.
        expect(neutral).toHaveLength(2);
        neutral.forEach((l) =>
            expect(l.getAttribute('data-opacity')).toBe('0.5'),
        );

        // Z-order: highlighted legs render last (trailing in DOM) so they stay on top.
        expect(layers.slice(0, 2)).toEqual(neutral);
        expect(layers.slice(2)).toEqual(primary);
    });
});
