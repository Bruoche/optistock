// Feature 012: assigns the current persisted tour to a driver for the selected
// date. A failed assignment toasts and reports failure so the caller stays on the
// presentation phase without navigating (FR-011).
import { useCallback } from 'react';
import { toast } from 'sonner';
import { postJson } from '@/lib/http';

export function useAssignDriver(tourId: number) {
    const assign = useCallback(
        async (
            driverId: number,
            date: string,
            startIndex: number,
        ): Promise<boolean> => {
            try {
                const response = await postJson(`/api/tour/${tourId}/assign`, {
                    driver_id: driverId,
                    date,
                    start_index: startIndex,
                });

                if (!response.ok) {
                    toast.error('Could not assign the tour to this driver.');

                    return false;
                }

                return true;
            } catch {
                toast.error('Network error — could not assign the tour.');

                return false;
            }
        },
        [tourId],
    );

    return { assign };
}
