import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { ResultSummary } from './result-summary';
import type { Driver, TourResult } from '@/types/tour';

// Radix Select (the result-view mode selector) needs these jsdom shims.
beforeAll(() => {
    Element.prototype.hasPointerCapture = vi.fn();
    Element.prototype.releasePointerCapture = vi.fn();
    Element.prototype.scrollIntoView = vi.fn();
});

const mockUseTourDrivers = vi.fn();
const mockAssign = vi.fn();

vi.mock('@/hooks/use-tour-drivers', () => ({
    useTourDrivers: (mode: string, date: string) =>
        mockUseTourDrivers(mode, date),
}));

vi.mock('@/hooks/use-assign-driver', () => ({
    useAssignDriver: () => ({ assign: mockAssign }),
}));

const DATE = '2026-07-06';

const result: TourResult = {
    id: 42,
    ordered_stops: [
        { lat: 1, lng: 2, order: 0 },
        { lat: 3, lng: 4, order: 1 },
    ],
    total_distance_m: 1000,
    total_duration_s: 600,
};

function driver(overrides: Partial<Driver> = {}): Driver {
    return {
        id: 1,
        name: 'Amelie',
        imageUrl: null,
        modes: ['driving'],
        warehouseName: 'North Depot',
        projectedSeconds: 0,
        projectedIncomplete: false,
        startIndex: 0,
        legs: [],
        ...overrides,
    };
}

function renderSummary(
    props: Partial<React.ComponentProps<typeof ResultSummary>> = {},
) {
    return render(
        <ResultSummary
            result={result}
            waitTimeS={0}
            driverMode="driving"
            onDriverModeChange={() => {}}
            date={DATE}
            selectedDriver={null}
            onSelectDriver={() => {}}
            onDateChange={() => {}}
            onReset={() => {}}
            onAssigned={() => {}}
            {...props}
        />,
    );
}

describe('ResultSummary', () => {
    it('renders the DriverList for the optimized mode', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });

        renderSummary();

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
        renderSummary({ waitTimeS: 2400 });

        expect(figureValue(/time on road/i)).toBe('10 min');
        expect(figureValue(/tour duration/i)).toBe('50 min');
    });

    it('treats an unavailable Time on road as 0 toward Tour duration (FR-011)', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });

        renderSummary({
            result: { ...result, total_duration_s: null },
            waitTimeS: 1500,
        });

        expect(figureValue(/time on road/i)).toBe('Unavailable');
        expect(figureValue(/tour duration/i)).toBe('25 min');
    });

    it('uses road metrics for both figures once they arrive (FR-008)', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });

        renderSummary({
            result: { ...result, total_duration_s: null },
            roadMetrics: { distance_m: 5000, duration_s: 1200 },
            waitTimeS: 1500,
        });

        expect(figureValue(/time on road/i)).toBe('20 min');
        expect(figureValue(/tour duration/i)).toBe('45 min');
    });

    it('disables Assign Driver while no driver is selected, right of New tour', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });

        renderSummary();

        const assignButton = screen.getByRole('button', {
            name: /assign driver/i,
        });
        expect(assignButton).toBeDisabled();

        const newTourButton = screen.getByRole('button', {
            name: /new tour/i,
        });
        expect(
            newTourButton.compareDocumentPosition(assignButton) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('enables Assign Driver once a driver is selected and opens the confirmation dialog', async () => {
        const selected = driver();
        mockUseTourDrivers.mockReturnValue({
            drivers: [selected],
            status: 'ready',
        });

        renderSummary({ selectedDriver: selected });

        const assignButton = screen.getByRole('button', {
            name: /assign driver/i,
        });
        expect(assignButton).toBeEnabled();

        fireEvent.click(assignButton);
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText(/assign this delivery/i)).toBeInTheDocument();
    });

    it('hides the driver rows from interaction while the dialog is open (one confirmation at a time)', async () => {
        const selected = driver();
        mockUseTourDrivers.mockReturnValue({
            drivers: [selected],
            status: 'ready',
        });

        renderSummary({ selectedDriver: selected });
        fireEvent.click(screen.getByRole('button', { name: /assign driver/i }));

        // The modal removes the page behind it from the accessibility tree, so
        // no driver row can be clicked while the confirmation is open.
        expect(
            screen.queryByRole('button', { name: /Amelie/ }),
        ).not.toBeInTheDocument();
    });

    it('cancelling the dialog keeps the selection and assigns nothing', async () => {
        const selected = driver();
        const onSelectDriver = vi.fn();
        const onAssigned = vi.fn();
        mockUseTourDrivers.mockReturnValue({
            drivers: [selected],
            status: 'ready',
        });

        renderSummary({ selectedDriver: selected, onSelectDriver, onAssigned });
        fireEvent.click(screen.getByRole('button', { name: /assign driver/i }));
        fireEvent.click(screen.getByRole('button', { name: /cancel/i }));

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(mockAssign).not.toHaveBeenCalled();
        expect(onAssigned).not.toHaveBeenCalled();
        expect(onSelectDriver).not.toHaveBeenCalled();
        expect(
            screen.getByRole('button', { name: /assign driver/i }),
        ).toBeEnabled();
    });

    it('confirming assigns the selected driver and fires onAssigned', async () => {
        const selected = driver();
        const onAssigned = vi.fn();
        mockAssign.mockResolvedValue(true);
        mockUseTourDrivers.mockReturnValue({
            drivers: [selected],
            status: 'ready',
        });

        renderSummary({ selectedDriver: selected, onAssigned });
        fireEvent.click(screen.getByRole('button', { name: /assign driver/i }));
        fireEvent.click(screen.getByRole('button', { name: /confirm/i }));

        expect(mockAssign).toHaveBeenCalledWith(selected.id, DATE, 0);
        await waitFor(() => expect(onAssigned).toHaveBeenCalled());
    });

    it('New tour opens a confirmation and does not reset until confirmed (US1)', () => {
        const onReset = vi.fn();
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });

        renderSummary({ onReset });
        fireEvent.click(screen.getByRole('button', { name: /new tour/i }));

        expect(
            screen.getByText(
                'Are you sure you want to make a new tour? The tour will remain unassigned.',
            ),
        ).toBeInTheDocument();
        expect(onReset).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: /confirm/i }));
        expect(onReset).toHaveBeenCalledTimes(1);
    });

    it('cancelling the New tour confirmation does not reset (US1)', () => {
        const onReset = vi.fn();
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });

        renderSummary({ onReset });
        fireEvent.click(screen.getByRole('button', { name: /new tour/i }));
        fireEvent.click(screen.getByRole('button', { name: /cancel/i }));

        expect(onReset).not.toHaveBeenCalled();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('renders the mode selector for driverMode and feeds it to the driver list (US2)', () => {
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });

        renderSummary({ driverMode: 'walking' });

        expect(
            screen.getByRole('combobox', { name: /delivery mode/i }),
        ).toHaveTextContent('Walking');
        expect(mockUseTourDrivers).toHaveBeenCalledWith('walking', DATE);
    });

    it('changing the mode selector calls onDriverModeChange (US2)', () => {
        const onDriverModeChange = vi.fn();
        mockUseTourDrivers.mockReturnValue({ drivers: [], status: 'ready' });

        renderSummary({ driverMode: 'trucking', onDriverModeChange });
        fireEvent.click(
            screen.getByRole('combobox', { name: /delivery mode/i }),
        );
        fireEvent.click(screen.getByRole('option', { name: 'Walking' }));

        expect(onDriverModeChange).toHaveBeenCalledWith('walking');
    });
});
