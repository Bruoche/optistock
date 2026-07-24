import { Loader2, TriangleAlert } from 'lucide-react';
import { DriverSummary } from '@/components/driver/driver-summary';
import { useTourDrivers } from '@/hooks/use-tour-drivers';
import { cn } from '@/lib/utils';
import { formatDurationHm } from '@/types/tour';
import type { DeliveryMode, Driver } from '@/types/tour';
import type { ReactNode } from 'react';

type DriverListProps = {
    /** The mode the shown tour was optimized with. */
    mode: DeliveryMode;
    /** The selected tour date (YYYY-MM-DD); its weekday narrows the list. */
    date: string;
    /** The persisted tour to project + assign when a driver is picked. */
    tourId: number;
    /** The driver whose projected workday is previewed, if any (feature 014). */
    selectedDriver: Driver | null;
    /** Called on row click; the owner toggles the selection (re-click deselects). */
    onSelect: (driver: Driver) => void;
};

function StatusLine({
    children,
    tone = 'muted',
}: {
    children: ReactNode;
    tone?: 'muted' | 'error';
}) {
    return (
        <div
            className={cn(
                'flex flex-1 items-center justify-center gap-2 text-sm',
                tone === 'error' ? 'text-destructive' : 'text-muted-foreground',
            )}
        >
            {children}
        </div>
    );
}

// A bracketing-connection time figure (feature 017): muted label + value, matching the
// total's structure. A null (unroutable) leg reads "Unavailable" rather than a false 0.
function RoadFigure({
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

// The extra rest break this candidate tour adds to the driver's day (feature 019). Shown only when
// positive, in the primary emphasis role with a "+" — it appears only sometimes and is the delta,
// not the day's total break.
function BreakFigure({ seconds }: { seconds: number }) {
    return (
        <div>
            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                Required break
            </p>
            <p className="font-semibold text-primary">
                +{formatDurationHm(seconds)}
            </p>
        </div>
    );
}

export function DriverList({
    mode,
    date,
    tourId,
    selectedDriver,
    onSelect,
}: DriverListProps) {
    const { drivers, status } = useTourDrivers(mode, date, tourId);

    if (status === 'loading') {
        return (
            <StatusLine>
                <Loader2 className="size-4 animate-spin" />
                Checking available drivers…
            </StatusLine>
        );
    }

    if (status === 'error') {
        return (
            <StatusLine tone="error">
                Could not load available drivers.
            </StatusLine>
        );
    }

    if (drivers.length === 0) {
        return <StatusLine>No one available for this delivery.</StatusLine>;
    }

    return (
        <ul className="min-h-0 flex-1 space-y-1 overflow-y-auto max-md:flex-none">
            {drivers.map((driver) => {
                const selected = driver.id === selectedDriver?.id;

                return (
                    <li key={driver.id}>
                        <button
                            type="button"
                            onClick={() => onSelect(driver)}
                            aria-pressed={selected}
                            className={cn(
                                'flex w-full items-center gap-3 scroll-x-contained rounded-md border px-3 py-2 text-left transition-colors hover:bg-secondary hover:text-secondary-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden',
                                selected
                                    ? 'border-primary bg-secondary text-secondary-foreground'
                                    : 'border-border',
                            )}
                        >
                            <DriverSummary
                                name={driver.name}
                                imageUrl={driver.imageUrl}
                                modes={driver.modes}
                                warehouseName={driver.warehouseName}
                            />

                            <div className="ml-auto flex shrink-0 items-start gap-4 text-right">
                                {driver.addedBreak > 0 && (
                                    <BreakFigure seconds={driver.addedBreak} />
                                )}
                                <RoadFigure
                                    label="To tour"
                                    seconds={driver.timeToTour}
                                />
                                <RoadFigure
                                    label="To warehouse"
                                    seconds={driver.timeFromTour}
                                />
                                <div>
                                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Projected workday
                                    </p>
                                    <p className="flex items-center justify-end gap-1 font-semibold">
                                        {driver.projectedIncomplete && (
                                            <TriangleAlert
                                                className="size-4 text-accent"
                                                aria-label="Approximate — some travel time could not be calculated"
                                            />
                                        )}
                                        {driver.projectedIncomplete && '≥ '}
                                        {formatDurationHm(
                                            driver.projectedSeconds,
                                        )}
                                    </p>
                                </div>
                            </div>
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}
