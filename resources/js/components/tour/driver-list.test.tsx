import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DriverList } from './driver-list';
import type { Driver } from '@/types/tour';

const mockUseTourDrivers = vi.fn();

vi.mock('@/hooks/use-tour-drivers', () => ({
    useTourDrivers: (mode: string, date: string, tourId: number) =>
        mockUseTourDrivers(mode, date, tourId),
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
        warehouseCoordinate: [48.85, 2.35],
        previousTourEnd: null,
        legs: [],
        ...overrides,
    };
}

function renderList(
    props: Partial<React.ComponentProps<typeof DriverList>> = {},
) {
    return render(
        <DriverList
            mode="driving"
            date="2026-07-06"
            tourId={42}
            selectedDriver={null}
            onSelect={() => {}}
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

    it('queries by mode, date and tour, and re-queries with no stale rows when the date changes', () => {
        mockUseTourDrivers.mockImplementation((_mode: string, date: string) =>
            date === '2026-07-06'
                ? {
                      status: 'ready',
                      drivers: [driver({ name: 'Monday Mona' })],
                  }
                : { status: 'ready', drivers: [] },
        );

        renderList();
        expect(mockUseTourDrivers).toHaveBeenLastCalledWith(
            'driving',
            '2026-07-06',
            42,
        );
        expect(screen.getByText('Monday Mona')).toBeInTheDocument();

        renderList({ date: '2026-07-04' });
        expect(mockUseTourDrivers).toHaveBeenLastCalledWith(
            'driving',
            '2026-07-04',
            42,
        );
    });

    it('renders drivers with mode icons, an image placeholder, and their warehouse', () => {
        mockUseTourDrivers.mockReturnValue({
            status: 'ready',
            drivers: [
                driver({
                    id: 1,
                    name: 'Amelie',
                    modes: ['driving', 'walking'],
                    warehouseName: 'North Depot',
                }),
                driver({
                    id: 2,
                    name: 'Bruno',
                    imageUrl: '/storage/drivers/b.jpg',
                    warehouseName: 'South Depot',
                }),
            ],
        });

        const { container } = renderList();

        expect(screen.getByText('Amelie')).toBeInTheDocument();
        expect(screen.getByText('North Depot')).toBeInTheDocument();
        expect(screen.getByText('South Depot')).toBeInTheDocument();
        expect(screen.getByLabelText('Walking')).toBeInTheDocument();

        const images = container.querySelectorAll('img');
        expect(images).toHaveLength(1);
        expect(images[0]).toHaveAttribute('src', '/storage/drivers/b.jpg');
    });

    it('shows the projected working day for each driver', () => {
        mockUseTourDrivers.mockReturnValue({
            status: 'ready',
            drivers: [driver({ name: 'Loaded Lena', projectedSeconds: 5400 })],
        });

        renderList();
        // 5400 s → 1 h 30 min.
        expect(screen.getByText(/1 h 30 min/)).toBeInTheDocument();
    });

    it('shows the two bracketing road times, and Unavailable for an unroutable leg (US1)', () => {
        mockUseTourDrivers.mockReturnValue({
            status: 'ready',
            drivers: [
                driver({
                    name: 'Amelie',
                    timeToTour: 1320,
                    timeFromTour: null,
                }),
            ],
        });

        renderList();

        expect(screen.getByText('Road to tour')).toBeInTheDocument();
        expect(screen.getByText('Road to warehouse')).toBeInTheDocument();
        // 1320 s → 22 min; null → "Unavailable".
        expect(screen.getByText(/22 min/)).toBeInTheDocument();
        expect(screen.getByText('Unavailable')).toBeInTheDocument();
    });

    it('labels the total "Total projected workday" and orders the three figures (US2)', () => {
        mockUseTourDrivers.mockReturnValue({
            status: 'ready',
            drivers: [driver({ name: 'Amelie' })],
        });

        renderList();

        expect(screen.getByText('Total projected workday')).toBeInTheDocument();
        expect(screen.queryByText('Projected')).not.toBeInTheDocument();

        const toTour = screen.getByText('Road to tour');
        const toWarehouse = screen.getByText('Road to warehouse');
        const total = screen.getByText('Total projected workday');
        expect(
            toTour.compareDocumentPosition(toWarehouse) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(
            toWarehouse.compareDocumentPosition(total) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('marks an incomplete projection as approximate', () => {
        mockUseTourDrivers.mockReturnValue({
            status: 'ready',
            drivers: [
                driver({
                    name: 'Unknown Uma',
                    projectedSeconds: 1800,
                    projectedIncomplete: true,
                }),
            ],
        });

        renderList();
        expect(screen.getByLabelText(/approximate/i)).toBeInTheDocument();
        expect(screen.getByText(/≥/)).toBeInTheDocument();
    });

    it('rows are buttons (keyboard-focusable, clickable to select)', () => {
        mockUseTourDrivers.mockReturnValue({
            status: 'ready',
            drivers: [driver({ name: 'Amelie' })],
        });

        renderList();
        expect(
            screen.getByRole('button', { name: /Amelie/ }),
        ).toBeInTheDocument();
    });

    it('clicking a row reports the driver to onSelect and opens no dialog', () => {
        const drivers = [driver({ name: 'Amelie' })];
        mockUseTourDrivers.mockReturnValue({ status: 'ready', drivers });
        const onSelect = vi.fn();

        renderList({ onSelect });
        fireEvent.click(screen.getByRole('button', { name: /Amelie/ }));

        expect(onSelect).toHaveBeenCalledWith(drivers[0]);
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('clicking the already-selected row reports it again so the owner can deselect', () => {
        const drivers = [driver({ name: 'Amelie' })];
        mockUseTourDrivers.mockReturnValue({ status: 'ready', drivers });
        const onSelect = vi.fn();

        renderList({ onSelect, selectedDriver: drivers[0] });
        fireEvent.click(screen.getByRole('button', { name: /Amelie/ }));

        expect(onSelect).toHaveBeenCalledWith(drivers[0]);
    });

    it('marks only the selected row as pressed', () => {
        const drivers = [
            driver({ id: 1, name: 'Amelie' }),
            driver({ id: 2, name: 'Bruno' }),
        ];
        mockUseTourDrivers.mockReturnValue({ status: 'ready', drivers });

        renderList({ selectedDriver: drivers[0] });

        expect(screen.getByRole('button', { name: /Amelie/ })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(screen.getByRole('button', { name: /Bruno/ })).toHaveAttribute(
            'aria-pressed',
            'false',
        );
    });
});
