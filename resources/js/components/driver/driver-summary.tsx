// The shared driver-row identity block (feature 027): avatar/placeholder, name, delivery-mode
// icons, and the assigned warehouse. Reused by the tour-assignment driver list and the drivers
// directory so both present a driver identically (single source of the row's visual language).
import { Car, PersonStanding, Truck, UserRound, Warehouse } from 'lucide-react';
import { DELIVERY_MODES } from '@/types/tour';
import type { DeliveryMode } from '@/types/tour';
import type { LucideIcon } from 'lucide-react';

export const MODE_ICON: Record<DeliveryMode, LucideIcon> = {
    walking: PersonStanding,
    driving: Car,
    trucking: Truck,
};

// Reuse the app-wide mode labels (single source) for the icon aria-labels.
export const MODE_LABEL = Object.fromEntries(
    DELIVERY_MODES.map((mode) => [mode.value, mode.label]),
) as Record<DeliveryMode, string>;

type DriverSummaryProps = {
    name: string;
    imageUrl: string | null;
    modes: DeliveryMode[];
    warehouseName: string;
};

export function DriverSummary({
    name,
    imageUrl,
    modes,
    warehouseName,
}: DriverSummaryProps) {
    return (
        <>
            {imageUrl ? (
                <img
                    src={imageUrl}
                    alt=""
                    className="size-10 shrink-0 rounded-full object-cover"
                />
            ) : (
                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <UserRound className="size-5" />
                </span>
            )}

            <div className="min-w-0">
                <p className="truncate font-semibold">{name}</p>
                <div className="mt-0.5 flex items-center gap-1.5 text-muted-foreground">
                    {modes.map((mode) => {
                        const Icon = MODE_ICON[mode];

                        return (
                            <Icon
                                key={mode}
                                className="size-4"
                                aria-label={MODE_LABEL[mode]}
                            />
                        );
                    })}
                </div>
                <div className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                    <Warehouse className="size-3.5" />
                    <span className="truncate">{warehouseName}</span>
                </div>
            </div>
        </>
    );
}
