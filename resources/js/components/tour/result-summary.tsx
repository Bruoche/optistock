// FR-014/FR-015: after a result arrives, this replaces the Optimize button row.
// Shows the total tour duration at the top; the space the stop list occupied now
// holds the available-driver list for the tour's mode (feature 006). Selecting a
// driver previews their workday on the map; the Assign button (014) opens the
// confirmation dialog that records the assignment (012). Edit (020) returns to the
// optimize menu to re-optimize this tour in place.
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ActionButton } from '@/components/action-button';
import { AssignDriverDialog } from '@/components/tour/assign-driver-dialog';
import { DriverList } from '@/components/tour/driver-list';
import { ModeSelect } from '@/components/tour/mode-select';
import { TourDateInput } from '@/components/tour/tour-date-field';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { formatDurationHm, formatWeekday } from '@/types/tour';
import type { DeliveryMode, Driver, TourResult } from '@/types/tour';
import type { ReactNode } from 'react';

type ResultSummaryProps = {
    result: TourResult;
    /** Road-accurate metrics (feature 002); when present and non-null they override the initial estimate. */
    roadMetrics?: {
        distance_m: number | null;
        duration_s: number | null;
    } | null;
    /** Sum of the stops' delivery durations in seconds (feature 007). */
    waitTimeS: number;
    /** The mode driving the available-driver list; defaults to the tour's optimization
     *  mode and is switchable in the result view (feature 016) without re-optimizing. */
    driverMode: DeliveryMode;
    /** Called when the result-view mode selector changes (reloads the driver list). */
    onDriverModeChange: (mode: DeliveryMode) => void;
    /** The selected tour date (YYYY-MM-DD); its weekday narrows the driver list (011). */
    date: string;
    /** The driver whose projected workday is previewed, if any (feature 014). */
    selectedDriver: Driver | null;
    /** Called on driver-row click; the owner toggles the selection. */
    onSelectDriver: (driver: Driver) => void;
    onDateChange: (date: string) => void;
    onReset: () => void;
    /** Called after the tour is assigned to a driver (feature 012) → clears the tour. */
    onAssigned: () => void;
};

// Subgrid cell: label and value snap to the header's two shared rows, so all
// figures stay aligned even though the date input is taller than the text values.
function Figure({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="row-span-2 grid grid-rows-subgrid">
            <p className="text-xs tracking-wide uppercase">{label}</p>
            <div className="flex items-center gap-2 text-lg font-semibold">
                {children}
            </div>
        </div>
    );
}

function formatDuration(totalSeconds: number | null): string {
    // A null road time is undetermined (no routing call / API failure) — shown as
    // unavailable rather than a misleading zero (FR-012).
    if (totalSeconds === null) {
        return 'Unavailable';
    }

    return formatDurationHm(totalSeconds);
}

export function ResultSummary({
    result,
    roadMetrics,
    waitTimeS,
    driverMode,
    onDriverModeChange,
    date,
    selectedDriver,
    onSelectDriver,
    onDateChange,
    onReset,
    onAssigned,
}: ResultSummaryProps) {
    // Prefer the road-accurate duration once available; otherwise the initial estimate.
    const durationS = roadMetrics?.duration_s ?? result.total_duration_s;
    // Unavailable road time contributes 0 to the tour duration (FR-011).
    const tourDurationS = (durationS ?? 0) + waitTimeS;

    const [confirming, setConfirming] = useState(false);
    const [confirmingNewTour, setConfirmingNewTour] = useState(false);

    return (
        <div className="flex h-full flex-col gap-3">
            <div className="flex items-center justify-between gap-6 rounded-md bg-primary px-4 py-3 text-text-on-color">
                <div className="grid auto-cols-max grid-flow-col grid-rows-[auto_auto] items-center gap-x-6 gap-y-1">
                    <Figure label="Time on road">
                        {formatDuration(durationS)}
                    </Figure>
                    <Figure label="Tour duration">
                        {formatDuration(tourDurationS)}
                    </Figure>
                    <Figure label="Mode">
                        <ModeSelect
                            value={driverMode}
                            onChange={onDriverModeChange}
                        />
                    </Figure>
                    <Figure label="Selected date">
                        <TourDateInput
                            date={date}
                            onDateChange={onDateChange}
                        />
                        {formatWeekday(date)}
                    </Figure>
                </div>
                <div className="flex items-center gap-2">
                    <ActionButton onClick={() => setConfirmingNewTour(true)}>
                        New
                    </ActionButton>
                    <ActionButton
                        onClick={() => router.visit(`/tour/${result.id}/edit`)}
                    >
                        Edit
                    </ActionButton>
                    <ActionButton
                        disabled={selectedDriver === null}
                        onClick={() => setConfirming(true)}
                    >
                        Assign
                    </ActionButton>
                </div>
            </div>

            <DriverList
                mode={driverMode}
                date={date}
                tourId={result.id}
                selectedDriver={selectedDriver}
                onSelect={onSelectDriver}
            />

            <AssignDriverDialog
                driver={confirming ? selectedDriver : null}
                tourId={result.id}
                date={date}
                startIndex={selectedDriver?.startIndex ?? 0}
                onOpenChange={(open) => {
                    if (!open) {
                        setConfirming(false);
                    }
                }}
                onAssigned={onAssigned}
            />

            <ConfirmDialog
                open={confirmingNewTour}
                onOpenChange={setConfirmingNewTour}
                title="Make a new tour?"
                description="Are you sure you want to make a new tour? The tour will remain unassigned."
                onConfirm={() => {
                    setConfirmingNewTour(false);
                    onReset();
                }}
            />
        </div>
    );
}
