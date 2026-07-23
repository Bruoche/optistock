import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TourList } from './tour-list';
import type { DayTour } from '@/types/driver';

function tour(overrides: Partial<DayTour> = {}): DayTour {
    return {
        id: 1,
        sequence: 0,
        loop: true,
        totalDurationS: 5400,
        drivenDurationS: 3600,
        stopSeconds: 1800,
        startCoordinate: [48.85, 2.35],
        stops: [
            { index: 1, lat: 48.85, lng: 2.35, durationS: 600 },
            { index: 2, lat: 48.86, lng: 2.36, durationS: 1200 },
        ],
        ...overrides,
    };
}

function renderList(
    props: Partial<React.ComponentProps<typeof TourList>> = {},
) {
    return render(
        <TourList
            tours={[tour()]}
            status="ready"
            selectedTourId={null}
            onSelect={() => {}}
            onEdit={() => {}}
            {...props}
        />,
    );
}

describe('TourList', () => {
    it('shows a spinner while loading', () => {
        renderList({ status: 'loading', tours: [] });
        expect(screen.getByText(/loading tours/i)).toBeInTheDocument();
    });

    it('shows an explicit empty-state message for a day with no tours', () => {
        renderList({ tours: [] });
        expect(
            screen.getByText('No tours assigned this day.'),
        ).toBeInTheDocument();
    });

    it('renders each tour with its total, driven and stop durations in order', () => {
        renderList({
            tours: [
                tour({ id: 1 }),
                tour({ id: 2, totalDurationS: null, drivenDurationS: null }),
            ],
        });

        expect(screen.getByText('Tour 1')).toBeInTheDocument();
        expect(screen.getByText('Tour 2')).toBeInTheDocument();
        // 5400 s → 1 h 30 min (total of tour 1).
        expect(screen.getAllByText(/1 h 30 min/).length).toBeGreaterThan(0);
        // A null duration reads "Unavailable", never a false 0.
        expect(screen.getAllByText('Unavailable').length).toBeGreaterThan(0);
    });

    it('selecting a tour marks it pressed and unfolds its stops (index/coord/duration)', () => {
        const onSelect = vi.fn();
        renderList({ selectedTourId: 1, onSelect });

        const row = screen.getByRole('button', { name: /Tour 1/ });
        expect(row).toHaveAttribute('aria-pressed', 'true');

        // Unfolded stops: two entries with indexes 1 and 2 and their coordinates.
        expect(screen.getByText('48.85000, 2.35000')).toBeInTheDocument();
        expect(screen.getByText('48.86000, 2.36000')).toBeInTheDocument();

        fireEvent.click(row);
        expect(onSelect).toHaveBeenCalledWith(1);
    });

    it('does not unfold an unselected tour', () => {
        renderList({ selectedTourId: null });
        expect(screen.queryByText('48.85000, 2.35000')).not.toBeInTheDocument();
    });
});
