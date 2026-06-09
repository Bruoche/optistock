import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { DriverList } from './driver-list';

const mockUseTourDrivers = vi.fn();

vi.mock('@/hooks/use-tour-drivers', () => ({
    useTourDrivers: (mode: string) => mockUseTourDrivers(mode),
}));

describe('DriverList', () => {
    beforeEach(() => {
        mockUseTourDrivers.mockReset();
    });

    it('shows the spinner + text while loading', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'loading' });
        render(<DriverList mode="driving" />);
        expect(
            screen.getByText(/checking available drivers/i),
        ).toBeInTheDocument();
    });

    it('shows the empty message when no driver matches', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });
        render(<DriverList mode="trucking" />);
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
                },
                {
                    id: 2,
                    name: 'Bruno',
                    imageUrl: '/storage/drivers/b.jpg',
                    modes: ['driving'],
                },
            ],
        });

        const { container } = render(<DriverList mode="driving" />);

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
});
