import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DriverList } from './driver-list';

const mockUseTourDrivers = vi.fn();

vi.mock('@/hooks/use-tour-drivers', () => ({
    useTourDrivers: (mode: string, date: string) =>
        mockUseTourDrivers(mode, date),
}));

function renderList(
    props: Partial<React.ComponentProps<typeof DriverList>> = {},
) {
    return render(
        <DriverList
            mode="driving"
            date="2026-07-06"
            tourId={42}
            currentTourTotalS={0}
            onAssigned={() => {}}
            {...props}
        />,
    );
}

describe('DriverList', () => {
    beforeEach(() => {
        mockUseTourDrivers.mockReset();
    });

    it('shows the spinner + text while loading', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'loading' });
        renderList();
        expect(
            screen.getByText(/checking available drivers/i),
        ).toBeInTheDocument();
    });

    it('shows the empty message when no driver matches', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });
        renderList({ mode: 'trucking' });
        expect(
            screen.getByText('No one available for this delivery.'),
        ).toBeInTheDocument();
    });

    it('queries by the given mode and date, and re-queries with no stale rows when the date changes', () => {
        mockUseTourDrivers.mockImplementation((_mode: string, date: string) =>
            date === '2026-07-06'
                ? {
                      status: 'ready',
                      drivers: [
                          {
                              id: 1,
                              name: 'Monday Mona',
                              imageUrl: null,
                              modes: ['driving'],
                              assignedSeconds: 0,
                          },
                      ],
                  }
                : { status: 'ready', drivers: [] },
        );

        const { rerender } = renderList();
        expect(mockUseTourDrivers).toHaveBeenLastCalledWith(
            'driving',
            '2026-07-06',
        );
        expect(screen.getByText('Monday Mona')).toBeInTheDocument();

        rerender(
            <DriverList
                mode="driving"
                date="2026-07-04"
                tourId={42}
                currentTourTotalS={0}
                onAssigned={() => {}}
            />,
        );
        expect(mockUseTourDrivers).toHaveBeenLastCalledWith(
            'driving',
            '2026-07-04',
        );
        expect(screen.queryByText('Monday Mona')).not.toBeInTheDocument();
        expect(
            screen.getByText('No one available for this delivery.'),
        ).toBeInTheDocument();
    });

    it('renders matching drivers in order, with mode icons and an image placeholder', () => {
        mockUseTourDrivers.mockReturnValue({
            status: 'ready',
            drivers: [
                {
                    id: 1,
                    name: 'Amelie',
                    imageUrl: null,
                    modes: ['driving', 'walking'],
                    assignedSeconds: 0,
                },
                {
                    id: 2,
                    name: 'Bruno',
                    imageUrl: '/storage/drivers/b.jpg',
                    modes: ['driving'],
                    assignedSeconds: 0,
                },
            ],
        });

        const { container } = renderList();

        const names = screen
            .getAllByText(/^(Amelie|Bruno)$/)
            .map((node) => node.textContent);
        expect(names).toEqual(['Amelie', 'Bruno']);

        // Amelie supports both modes; both icons present. Driving appears for both drivers.
        expect(screen.getByLabelText('Walking')).toBeInTheDocument();
        expect(screen.getAllByLabelText('Driving')).toHaveLength(2);

        // Only Bruno has an image; Amelie falls back to the placeholder (no <img>).
        const images = container.querySelectorAll('img');
        expect(images).toHaveLength(1);
        expect(images[0]).toHaveAttribute('src', '/storage/drivers/b.jpg');
    });

    it('shows projected hours = committed + current tour total, and just the tour for a fresh driver', () => {
        mockUseTourDrivers.mockReturnValue({
            status: 'ready',
            drivers: [
                {
                    id: 1,
                    name: 'Loaded Lena',
                    imageUrl: null,
                    modes: ['driving'],
                    assignedSeconds: 3600,
                },
                {
                    id: 2,
                    name: 'Fresh Fred',
                    imageUrl: null,
                    modes: ['driving'],
                    assignedSeconds: 0,
                },
            ],
        });

        // Current tour total 1800 s (30 min).
        renderList({ currentTourTotalS: 1800 });

        // Lena: 3600 + 1800 = 5400 s → 1 h 30 min.
        expect(screen.getByText('1 h 30 min')).toBeInTheDocument();
        // Fred: 0 + 1800 = 1800 s → 30 min.
        expect(screen.getByText('30 min')).toBeInTheDocument();
    });

    it('rows are buttons (keyboard-focusable, clickable to assign)', () => {
        mockUseTourDrivers.mockReturnValue({
            status: 'ready',
            drivers: [
                {
                    id: 1,
                    name: 'Amelie',
                    imageUrl: null,
                    modes: ['driving'],
                    assignedSeconds: 0,
                },
            ],
        });

        renderList();
        expect(
            screen.getByRole('button', { name: /Amelie/ }),
        ).toBeInTheDocument();
    });
});
