import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ResultSummary } from './result-summary';
import type { TourResult } from '@/types/tour';

const mockUseTourDrivers = vi.fn();

vi.mock('@/hooks/use-tour-drivers', () => ({
    useTourDrivers: (mode: string, date: string) =>
        mockUseTourDrivers(mode, date),
}));

const DATE = '2026-07-06';

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
            <ResultSummary
                result={result}
                waitTimeS={0}
                mode="driving"
                date={DATE}
                onDateChange={() => {}}
                onReset={() => {}}
            />,
        );

        // ResultSummary mounts DriverList (empty state proves it rendered) for the mode + date.
        expect(mockUseTourDrivers).toHaveBeenCalledWith('driving', DATE);
        expect(
            screen.getByText('No one available for this delivery.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /new tour/i }),
        ).toBeInTheDocument();
    });

    function figureValue(label: RegExp): string {
        // The value <p> is the sibling following the label <p> within a Figure.
        return screen.getByText(label).nextElementSibling?.textContent ?? '';
    }

    it('shows Time on road and Tour duration = road + wait (FR-006/FR-007)', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });

        // total_duration_s 600 (10 min) + waitTimeS 2400 (40 min) → Tour duration 50 min.
        render(
            <ResultSummary
                result={result}
                waitTimeS={2400}
                mode="driving"
                date={DATE}
                onDateChange={() => {}}
                onReset={() => {}}
            />,
        );

        expect(figureValue(/time on road/i)).toBe('10 min');
        expect(figureValue(/tour duration/i)).toBe('50 min');
    });

    it('treats an unavailable Time on road as 0 toward Tour duration (FR-011)', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });
        const noMetrics: TourResult = { ...result, total_duration_s: null };

        render(
            <ResultSummary
                result={noMetrics}
                waitTimeS={1500}
                mode="driving"
                date={DATE}
                onDateChange={() => {}}
                onReset={() => {}}
            />,
        );

        expect(figureValue(/time on road/i)).toBe('Unavailable');
        expect(figureValue(/tour duration/i)).toBe('25 min');
    });

    it('uses road metrics for both figures once they arrive (FR-008)', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });
        const noMetrics: TourResult = { ...result, total_duration_s: null };

        render(
            <ResultSummary
                result={noMetrics}
                roadMetrics={{ distance_m: 5000, duration_s: 1200 }}
                waitTimeS={1500}
                mode="driving"
                date={DATE}
                onDateChange={() => {}}
                onReset={() => {}}
            />,
        );

        expect(figureValue(/time on road/i)).toBe('20 min');
        expect(figureValue(/tour duration/i)).toBe('45 min');
    });
});
