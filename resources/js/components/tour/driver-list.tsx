// Feature 006: lists the drivers available for the optimized tour, in the region
// the stop list held on the edit page. Name is prominent; supported-mode icons
// (walking figure / car / truck) sit beneath it. Empty → a clear message.
import { Car, Loader2, PersonStanding, Truck, UserRound } from 'lucide-react';
import { useTourDrivers } from '@/hooks/use-tour-drivers';
import { cn } from '@/lib/utils';
import { DELIVERY_MODES } from '@/types/tour';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import type { DeliveryMode } from '@/types/tour';

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

export function DriverList({ mode }: DriverListProps) {
    const { drivers, status } = useTourDrivers(mode);

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
            {drivers.map((driver) => (
                <li
                    key={driver.id}
                    className="flex items-center gap-3 rounded-md border border-border px-3 py-2"
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
                        <p className="truncate font-semibold">{driver.name}</p>
                        <div className="mt-0.5 flex items-center gap-1.5 text-muted-foreground">
                            {driver.modes.map((driverMode) => {
                                const Icon = MODE_ICON[driverMode];

                                return (
                                    <Icon
                                        key={driverMode}
                                        className="size-4"
                                        aria-label={MODE_LABEL[driverMode]}
                                    />
                                );
                            })}
                        </div>
                    </div>
                </li>
            ))}
        </ul>
    );
}
