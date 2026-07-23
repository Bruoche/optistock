// The day bar (feature 025): day navigation (prev/next arrows + date field), the day's
// workday figures on the left, and the "Tour order" save on the right. An undetermined
// total reads as a lower bound with a warning (FR-013); the figures show placeholders
// until the day loads. The Tour-order action is a disabled placeholder until US4 wires it.
import {
    ChevronLeft,
    ChevronRight,
    Loader2,
    TriangleAlert,
} from 'lucide-react';
import { ActionButton } from '@/components/action-button';
import { TourDateInput } from '@/components/tour/tour-date-field';
import { formatDurationHm, formatWeekday } from '@/types/tour';
import type { DayWorkday } from '@/types/driver';
import type { ReactNode } from 'react';

// Shift a YYYY-MM-DD date by whole days, parsed as a LOCAL calendar date (noon) so it
// never rolls across a timezone boundary — matching the app's other date handling.
function shiftDate(date: string, days: number): string {
    const [year, month, day] = date.split('-').map(Number);
    const local = new Date(year, month - 1, day + days, 12);
    const pad = (value: number) => value.toString().padStart(2, '0');

    return `${local.getFullYear()}-${pad(local.getMonth() + 1)}-${pad(local.getDate())}`;
}

function Figure({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <p className="text-xs tracking-wide uppercase">{label}</p>
            <div className="flex items-center gap-1 text-lg font-semibold">
                {children}
            </div>
        </div>
    );
}

function WorkdayFigures({ workday }: { workday: DayWorkday | null }) {
    if (workday === null) {
        return (
            <Figure label="Total workday">
                <span>—</span>
            </Figure>
        );
    }

    const lowerBound = workday.incomplete;

    return (
        <>
            <Figure label="Total workday">
                {lowerBound && (
                    <TriangleAlert
                        className="size-4 text-accent"
                        aria-label="Approximate — some travel time could not be calculated"
                    />
                )}
                {lowerBound && '≥ '}
                {formatDurationHm(workday.totalSeconds)}
            </Figure>
            <Figure label="Driven">
                {lowerBound && '≥ '}
                {formatDurationHm(workday.drivenSeconds)}
            </Figure>
            <Figure label="Stops">
                {formatDurationHm(workday.stopSeconds)}
            </Figure>
            <Figure label="Break">
                {formatDurationHm(workday.breakSeconds)}
            </Figure>
        </>
    );
}

type DayBarProps = {
    date: string;
    onDateChange: (date: string) => void;
    workday: DayWorkday | null;
    /** Whether the running order has unsaved drag changes (US4). */
    orderDirty?: boolean;
    onSaveOrder?: () => void;
    /** A save in flight (disables the actions). */
    savingOrder?: boolean;
    /** The last normal save was blocked (routing down) — reveal the force-save escape hatch. */
    orderBlocked?: boolean;
    onForceSaveOrder?: () => void;
};

export function DayBar({
    date,
    onDateChange,
    workday,
    orderDirty = false,
    onSaveOrder,
    savingOrder = false,
    orderBlocked = false,
    onForceSaveOrder,
}: DayBarProps) {
    return (
        <div className="flex flex-wrap items-end justify-between gap-6 bg-primary px-4 py-3 text-text-on-color max-md:rounded-none">
            <div className="flex flex-wrap items-end gap-x-6 gap-y-1">
                <WorkdayFigures workday={workday} />
            </div>

            <div className="flex items-end gap-2">
                <ActionButton
                    aria-label="Previous day"
                    onClick={() => onDateChange(shiftDate(date, -1))}
                >
                    <ChevronLeft className="size-4" />
                </ActionButton>
                <div className="flex flex-col items-center">
                    <p className="text-xs tracking-wide uppercase">
                        {formatWeekday(date)}
                    </p>
                    <TourDateInput date={date} onDateChange={onDateChange} />
                </div>
                <ActionButton
                    aria-label="Next day"
                    onClick={() => onDateChange(shiftDate(date, 1))}
                >
                    <ChevronRight className="size-4" />
                </ActionButton>
            </div>

            <div className="flex flex-col items-end gap-1">
                <p className="text-xs tracking-wide uppercase">Tour order</p>
                <div className="flex items-center gap-2">
                    {orderBlocked && (
                        <ActionButton
                            disabled={savingOrder}
                            onClick={onForceSaveOrder}
                            className="bg-accent text-text-on-color hover:bg-accent/90"
                        >
                            Force save
                        </ActionButton>
                    )}
                    <ActionButton
                        disabled={!orderDirty || savingOrder}
                        onClick={onSaveOrder}
                    >
                        {savingOrder && (
                            <Loader2 className="size-4 animate-spin" />
                        )}
                        Update
                    </ActionButton>
                </div>
            </div>
        </div>
    );
}
