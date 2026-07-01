// FR-014/FR-015: after a result arrives, this replaces the Optimize button row.
// Shows the total tour duration at the top; the space the stop list occupied now
// holds the available-driver list for the tour's mode (feature 006).
import { ActionButton } from '@/components/action-button';
import { DriverList } from '@/components/tour/driver-list';
import { TourDateInput } from '@/components/tour/tour-date-field';
import { formatWeekday } from '@/types/tour';
import type { ReactNode } from 'react';
import type { DeliveryMode, TourResult } from '@/types/tour';

type ResultSummaryProps = {
    result: TourResult;
    /** Road-accurate metrics (feature 002); when present and non-null they override the initial estimate. */
    roadMetrics?: {
        distance_m: number | null;
        duration_s: number | null;
    } | null;
    /** Sum of the stops' delivery durations in seconds (feature 007). */
    waitTimeS: number;
    /** The mode the shown tour was optimized with — drives the available-driver list. */
    mode: DeliveryMode;
    /** The selected tour date (YYYY-MM-DD); its weekday narrows the driver list (011). */
    date: string;
    onDateChange: (date: string) => void;
    onReset: () => void;
};

// A header cell whose label and value snap to the two shared rows of the
// surrounding subgrid, so every figure's label and value stay aligned even when a
// value (the date input) is taller than the plain-text ones.
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
    // 2-point tours have no metrics yet (pending the /route/ endpoint).
    if (totalSeconds === null) {
        return 'Unavailable';
    }

    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.round((totalSeconds % 3600) / 60);

    if (hours > 0) {
        return `${hours} h ${minutes.toString().padStart(2, '0')} min`;
    }

    return `${minutes} min`;
}

export function ResultSummary({
    result,
    roadMetrics,
    waitTimeS,
    mode,
    date,
    onDateChange,
    onReset,
}: ResultSummaryProps) {
    // Prefer the road-accurate duration once available; otherwise the initial estimate.
    const durationS = roadMetrics?.duration_s ?? result.total_duration_s;
    // Unavailable road time contributes 0 to the tour duration (FR-011).
    const tourDurationS = (durationS ?? 0) + waitTimeS;

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
                    <Figure label="Selected date">
                        <TourDateInput date={date} onDateChange={onDateChange} />
                        {formatWeekday(date)}
                    </Figure>
                </div>
                <ActionButton onClick={onReset}>New tour</ActionButton>
            </div>

            <DriverList mode={mode} date={date} />
        </div>
    );
}
