import {
    Car,
    Loader2,
    PersonStanding,
    TriangleAlert,
    Truck,
    UserRound,
    Warehouse,
} from 'lucide-react';
import { useTourDrivers } from '@/hooks/use-tour-drivers';
import { cn } from '@/lib/utils';
import { DELIVERY_MODES, formatDurationHm } from '@/types/tour';
import type { DeliveryMode, Driver } from '@/types/tour';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

const MODE_ICON: Record<DeliveryMode, LucideIcon> = {
    walking: PersonStanding,
    driving: Car,
    trucking: Truck,
};

// Reuse the app-wide mode labels (single source) for the icon aria-labels.
const MODE_LABEL = Object.fromEntries(
    DELIVERY_MODES.map((mode) => [mode.value, mode.label]),
) as Record<DeliveryMode, string>;

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
        <ul className="flex-1 space-y-1 overflow-y-auto">
            {drivers.map((driver) => {
                const selected = driver.id === selectedDriver?.id;

                return (
                    <li key={driver.id}>
                        <button
                            type="button"
                            onClick={() => onSelect(driver)}
                            aria-pressed={selected}
                            className={cn(
                                'flex w-full items-center gap-3 rounded-md border px-3 py-2 text-left transition-colors hover:bg-secondary hover:text-secondary-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden',
                                selected
                                    ? 'border-primary bg-secondary text-secondary-foreground'
                                    : 'border-border',
                            )}
                        >
                            {driver.imageUrl ? (
                                <img
                                    src={driver.imageUrl}
                                    alt=""
                                    className="size-10 shrink-0 rounded-full object-cover"
                                />
                            ) : (
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                    <UserRound className="size-5" />
                                </span>
                            )}

                            <div className="min-w-0">
                                <p className="truncate font-semibold">
                                    {driver.name}
                                </p>
                                <div className="mt-0.5 flex items-center gap-1.5 text-muted-foreground">
                                    {driver.modes.map((driverMode) => {
                                        const Icon = MODE_ICON[driverMode];

                                        return (
                                            <Icon
                                                key={driverMode}
                                                className="size-4"
                                                aria-label={
                                                    MODE_LABEL[driverMode]
                                                }
                                            />
                                        );
                                    })}
                                </div>
                                <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                    <Warehouse className="size-3.5" />
                                    <span className="truncate">
                                        {driver.warehouseName}
                                    </span>
                                </div>
                            </div>

                            <div className="ml-auto flex shrink-0 items-start gap-4 text-right">
                                <RoadFigure
                                    label="Road to tour"
                                    seconds={driver.timeToTour}
                                />
                                <RoadFigure
                                    label="Road to warehouse"
                                    seconds={driver.timeFromTour}
                                />
                                <div>
                                    <p className="text-xs tracking-wide text-muted-foreground uppercase">
                                        Total projected workday
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
