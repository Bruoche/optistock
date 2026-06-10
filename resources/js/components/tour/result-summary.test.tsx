import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { TourResult } from '@/types/tour';
import { ResultSummary } from './result-summary';

const mockUseTourDrivers = vi.fn();

vi.mock('@/hooks/use-tour-drivers', () => ({
    useTourDrivers: (mode: string) => mockUseTourDrivers(mode),
}));

const result: TourResult = {
    ordered_stops: [
        { lat: 1, lng: 2, order: 0 },
        { lat: 3, lng: 4, order: 1 },
    ],
    total_distance_m: 1000,
    total_duration_s: 600,
};

describe('ResultSummary', () => {
    it('renders the DriverList for the optimized mode', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });

        render(
            <ResultSummary result={result} mode="driving" onReset={() => {}} />,
        );

        // ResultSummary mounts DriverList (empty state proves it rendered) for the mode.
        expect(mockUseTourDrivers).toHaveBeenCalledWith('driving');
        expect(
            screen.getByText('No one available for this delivery.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /new tour/i }),
        ).toBeInTheDocument();
    });
});
