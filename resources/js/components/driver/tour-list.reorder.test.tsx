import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { TourList } from './tour-list';
import type { DayTour } from '@/types/driver';

function tour(id: number): DayTour {
    return {
        id,
        sequence: id,
        loop: true,
        totalDurationS: 100,
        drivenDurationS: 60,
        stopSeconds: 40,
        startCoordinate: [48.85, 2.35],
        stops: [],
    };
}

describe('TourList reorder (US4)', () => {
    it('renders a drag handle per row when reorderable (>1 tour + onReorder)', () => {
        render(
            <TourList
                tours={[tour(1), tour(2), tour(3)]}
                status="ready"
                selectedTourId={null}
                onSelect={() => {}}
                onEdit={() => {}}
                onReorder={() => {}}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Reorder Tour 1' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Reorder Tour 3' }),
        ).toBeInTheDocument();
    });

    it('shows no drag handle on a single-tour day (handles are inert)', () => {
        render(
            <TourList
                tours={[tour(1)]}
                status="ready"
                selectedTourId={null}
                onSelect={() => {}}
                onEdit={() => {}}
                onReorder={() => {}}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /Reorder Tour/ }),
        ).not.toBeInTheDocument();
    });

    it('shows no drag handle when the list is not reorderable (no onReorder)', () => {
        render(
            <TourList
                tours={[tour(1), tour(2)]}
                status="ready"
                selectedTourId={null}
                onSelect={() => {}}
                onEdit={() => {}}
            />,
        );

        expect(
            screen.queryByRole('button', { name: /Reorder Tour/ }),
        ).not.toBeInTheDocument();
    });
});
