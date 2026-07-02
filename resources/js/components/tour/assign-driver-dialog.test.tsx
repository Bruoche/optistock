import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AssignDriverDialog } from './assign-driver-dialog';
import type { Driver } from '@/types/tour';

const mockAssign = vi.fn();

vi.mock('@/hooks/use-assign-driver', () => ({
    useAssignDriver: (tourId: number) => {
        capturedTourId = tourId;

        return { assign: mockAssign };
    },
}));

let capturedTourId = 0;

const driver: Driver = {
    id: 12,
    name: 'Amelie Durand',
    imageUrl: null,
    modes: ['driving'],
    warehouseName: 'North Depot',
    projectedSeconds: 0,
    projectedIncomplete: false,
    startIndex: 3,
};

const DATE = '2026-07-06';

function renderDialog(
    props: Partial<React.ComponentProps<typeof AssignDriverDialog>> = {},
) {
    return render(
        <AssignDriverDialog
            driver={driver}
            tourId={42}
            date={DATE}
            startIndex={3}
            onOpenChange={() => {}}
            onAssigned={() => {}}
            {...props}
        />,
    );
}

describe('AssignDriverDialog', () => {
    beforeEach(() => {
        mockAssign.mockReset();
        capturedTourId = 0;
    });

    it('names the driver being assigned', () => {
        renderDialog();
        expect(screen.getByText('Amelie Durand')).toBeInTheDocument();
    });

    it('confirming assigns the tour to the driver for the date and calls onAssigned on success', async () => {
        mockAssign.mockResolvedValue(true);
        const onAssigned = vi.fn();

        renderDialog({ onAssigned });
        fireEvent.click(screen.getByRole('button', { name: /confirm/i }));

        await waitFor(() => expect(mockAssign).toHaveBeenCalledWith(12, DATE, 3));
        expect(capturedTourId).toBe(42);
        await waitFor(() => expect(onAssigned).toHaveBeenCalledTimes(1));
    });

    it('does not call onAssigned when the assignment fails (FR-011)', async () => {
        mockAssign.mockResolvedValue(false);
        const onAssigned = vi.fn();

        renderDialog({ onAssigned });
        fireEvent.click(screen.getByRole('button', { name: /confirm/i }));

        await waitFor(() => expect(mockAssign).toHaveBeenCalled());
        expect(onAssigned).not.toHaveBeenCalled();
    });

    it('cancelling closes without assigning (US3)', () => {
        const onOpenChange = vi.fn();
        const onAssigned = vi.fn();

        renderDialog({ onOpenChange, onAssigned });
        fireEvent.click(screen.getByRole('button', { name: /cancel/i }));

        expect(mockAssign).not.toHaveBeenCalled();
        expect(onAssigned).not.toHaveBeenCalled();
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('renders nothing interactive when no driver is selected', () => {
        renderDialog({ driver: null });
        expect(
            screen.queryByRole('button', { name: /confirm/i }),
        ).not.toBeInTheDocument();
    });
});
