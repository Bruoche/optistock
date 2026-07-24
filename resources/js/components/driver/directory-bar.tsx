// The drivers-directory criteria bar (feature 027): a partial name search, a required-mode
// multi-toggle (all selected modes required), and an optional warehouse. Any change updates the
// list live. Wraps on narrow screens; all colours come from the role-named palette.
import { MODE_ICON } from '@/components/driver/driver-summary';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { DELIVERY_MODES } from '@/types/tour';
import type { DirectoryCriteria, WarehouseOption } from '@/types/driver';
import type { DeliveryMode } from '@/types/tour';

const ANY_WAREHOUSE = 'any';

type DirectoryBarProps = {
    criteria: DirectoryCriteria;
    warehouses: WarehouseOption[];
    onChange: (criteria: DirectoryCriteria) => void;
};

export function DirectoryBar({
    criteria,
    warehouses,
    onChange,
}: DirectoryBarProps) {
    function toggleMode(mode: DeliveryMode) {
        const modes = criteria.modes.includes(mode)
            ? criteria.modes.filter((selected) => selected !== mode)
            : [...criteria.modes, mode];

        onChange({ ...criteria, modes });
    }

    return (
        <div className="flex flex-wrap items-center gap-3 border-b border-border p-3">
            <Input
                type="search"
                value={criteria.name}
                onChange={(event) =>
                    onChange({ ...criteria, name: event.target.value })
                }
                placeholder="Search by name"
                aria-label="Search by name"
                className="w-56 shrink-0"
            />

            <div
                role="group"
                aria-label="Required transportation modes"
                className="flex shrink-0 items-center gap-1"
            >
                {DELIVERY_MODES.map((mode) => {
                    const Icon = MODE_ICON[mode.value];
                    const active = criteria.modes.includes(mode.value);

                    return (
                        <button
                            key={mode.value}
                            type="button"
                            onClick={() => toggleMode(mode.value)}
                            aria-pressed={active}
                            aria-label={mode.label}
                            title={mode.label}
                            className={cn(
                                'flex items-center gap-1.5 rounded-md border px-3 py-2 text-sm transition-colors hover:bg-secondary hover:text-secondary-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden',
                                active
                                    ? 'border-primary bg-secondary text-secondary-foreground'
                                    : 'border-border text-muted-foreground',
                            )}
                        >
                            <Icon className="size-4" />
                            {mode.label}
                        </button>
                    );
                })}
            </div>

            <Select
                value={
                    criteria.warehouseId === null
                        ? ANY_WAREHOUSE
                        : String(criteria.warehouseId)
                }
                onValueChange={(value) =>
                    onChange({
                        ...criteria,
                        warehouseId:
                            value === ANY_WAREHOUSE ? null : Number(value),
                    })
                }
            >
                <SelectTrigger aria-label="Warehouse" className="w-48 shrink-0">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ANY_WAREHOUSE}>Any warehouse</SelectItem>
                    {warehouses.map((warehouse) => (
                        <SelectItem
                            key={warehouse.id}
                            value={String(warehouse.id)}
                        >
                            {warehouse.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
