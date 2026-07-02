// Feature 012: confirmation before assigning the current tour to a driver. Built on
// the shared Dialog primitive; open when a driver is selected. Confirm assigns and
// calls onAssigned (which clears the tour); Cancel/dismiss closes with no assignment.
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAssignDriver } from '@/hooks/use-assign-driver';
import type { Driver } from '@/types/tour';

type AssignDriverDialogProps = {
    /** The driver being assigned; null closes the dialog. */
    driver: Driver | null;
    /** The persisted tour to assign. */
    tourId: number;
    /** The selected date the tour is assigned for (YYYY-MM-DD). */
    date: string;
    /** The stop position chosen as this driver's start (feature 013), sent so the
     *  server records the start/end without recomputing the selection. */
    startIndex: number;
    onOpenChange: (open: boolean) => void;
    /** Called after a successful assignment (clears the tour + returns to creation). */
    onAssigned: () => void;
};

export function AssignDriverDialog({
    driver,
    tourId,
    date,
    startIndex,
    onOpenChange,
    onAssigned,
}: AssignDriverDialogProps) {
    const { assign } = useAssignDriver(tourId);
    const [pending, setPending] = useState(false);

    async function handleConfirm() {
        if (driver === null) {
            return;
        }

        setPending(true);
        const assigned = await assign(driver.id, date, startIndex);
        setPending(false);

        // On failure the hook toasts and we stay open (FR-011); on success the tour
        // is cleared by onAssigned.
        if (assigned) {
            onAssigned();
        }
    }

    return (
        <Dialog open={driver !== null} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Assign this delivery?</DialogTitle>
                    <DialogDescription>
                        Assign the optimized tour to{' '}
                        <span className="font-semibold">{driver?.name}</span>{' '}
                        for {date}.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={pending}
                    >
                        Cancel
                    </Button>
                    <Button onClick={handleConfirm} disabled={pending}>
                        Confirm
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
