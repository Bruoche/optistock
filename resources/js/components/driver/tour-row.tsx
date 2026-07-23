// One tour in the day's list (feature 025): its total / driven / stop durations, selectable
// like the tour pages' driver rows (secondary on hover, primary when selected). When
// selected it unfolds to list its stops (index / coordinate / duration) — the same indexes
// the map's numbered markers use. The drag handle (US4) and Edit action (US5) attach here.
import { ActionButton } from '@/components/action-button';
import { cn } from '@/lib/utils';
import { formatDurationHm } from '@/types/tour';
import type { DayTour } from '@/types/driver';
import type { CSSProperties, ReactNode, Ref } from 'react';

function DurationFigure({
    label,
    seconds,
}: {
    label: string;
    seconds: number | null;
}) {
    return (
        <div>
            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="font-semibold">
                {seconds === null ? 'Unavailable' : formatDurationHm(seconds)}
            </p>
        </div>
    );
}

type TourRowProps = {
    tour: DayTour;
    /** 1-based running position, for the row's "Tour N" label + its map marker. */
    position: number;
    selected: boolean;
    onSelect: (tourId: number) => void;
    /** Opens the tour-edit screen for this tour (US5). */
    onEdit?: (tourId: number) => void;
    /** Optional slot at the row's far left (the drag handle in US4). */
    handle?: ReactNode;
    /** Sortable wiring (US4): applied to the row root when the list is reorderable. */
    rootRef?: Ref<HTMLLIElement>;
    rootStyle?: CSSProperties;
    rootAttributes?: Record<string, unknown>;
};

export function TourRow({
    tour,
    position,
    selected,
    onSelect,
    onEdit,
    handle,
    rootRef,
    rootStyle,
    rootAttributes,
}: TourRowProps) {
    return (
        <li ref={rootRef} style={rootStyle} {...rootAttributes}>
            <div
                className={cn(
                    'flex items-stretch scroll-x-contained rounded-md border transition-colors',
                    selected
                        ? 'border-primary bg-secondary text-secondary-foreground'
                        : 'border-border hover:bg-secondary hover:text-secondary-foreground',
                )}
            >
                {handle}
                <button
                    type="button"
                    onClick={() => onSelect(tour.id)}
                    aria-pressed={selected}
                    className="flex flex-1 items-center gap-4 px-3 py-2 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                >
                    <span className="font-semibold">Tour {position}</span>
                    <div className="ml-auto flex items-start gap-4 text-right">
                        <DurationFigure
                            label="Total"
                            seconds={tour.totalDurationS}
                        />
                        <DurationFigure
                            label="Driven"
                            seconds={tour.drivenDurationS}
                        />
                        <DurationFigure
                            label="Stops"
                            seconds={tour.stopSeconds}
                        />
                    </div>
                </button>
                {onEdit && (
                    <div className="flex items-center pr-2">
                        <ActionButton onClick={() => onEdit(tour.id)}>
                            Edit
                        </ActionButton>
                    </div>
                )}
            </div>

            {selected && (
                <ul className="mt-1 space-y-1 px-3 py-2 text-sm">
                    {tour.stops.map((stop) => (
                        <li
                            key={stop.index}
                            className="flex items-center gap-3 text-muted-foreground"
                        >
                            <span className="font-semibold text-foreground">
                                {stop.index}
                            </span>
                            <span className="tabular-nums">
                                {stop.lat.toFixed(5)}, {stop.lng.toFixed(5)}
                            </span>
                            <span className="ml-auto">
                                {formatDurationHm(stop.durationS)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}
