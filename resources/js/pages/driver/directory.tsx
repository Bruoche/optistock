// Drivers directory (feature 027): a criteria bar (name / required modes / warehouse) above a
// live-filtered list of every matching driver, name-sorted, each row linking to that driver's
// management page. The list is fetched client-side so criteria changes need no full page reload;
// loading, error, and no-match are distinct states — never a silent empty list.
import { Head, Link } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useState } from 'react';
import { DirectoryBar } from '@/components/driver/directory-bar';
import { DriverSummary } from '@/components/driver/driver-summary';
import { useDriversDirectory } from '@/hooks/use-drivers-directory';
import type { DirectoryCriteria, WarehouseOption } from '@/types/driver';

type DriverDirectoryProps = {
    warehouses: WarehouseOption[];
};

const EMPTY_CRITERIA: DirectoryCriteria = {
    name: '',
    modes: [],
    warehouseId: null,
};

export default function DriverDirectory({ warehouses }: DriverDirectoryProps) {
    const [criteria, setCriteria] = useState<DirectoryCriteria>(EMPTY_CRITERIA);
    const { drivers, status } = useDriversDirectory(criteria);

    return (
        <>
            <Head title="Drivers" />

            <div className="flex min-h-0 flex-1 flex-col">
                <DirectoryBar
                    criteria={criteria}
                    warehouses={warehouses}
                    onChange={setCriteria}
                />

                <div className="min-h-0 flex-1 overflow-y-auto p-3">
                    {status === 'loading' && (
                        <div className="flex items-center justify-center gap-2 py-8 text-sm text-muted-foreground">
                            <Loader2 className="size-4 animate-spin" />
                            Loading drivers…
                        </div>
                    )}

                    {status === 'error' && (
                        <div className="flex items-center justify-center py-8 text-sm text-destructive">
                            Could not load drivers.
                        </div>
                    )}

                    {status === 'ready' && drivers.length === 0 && (
                        <div className="flex items-center justify-center py-8 text-sm text-muted-foreground">
                            no drivers found with current criterias.
                        </div>
                    )}

                    {status === 'ready' && drivers.length > 0 && (
                        <ul className="space-y-1">
                            {drivers.map((driver) => (
                                <li key={driver.id}>
                                    <Link
                                        href={`/driver/${driver.id}`}
                                        className="flex w-full items-center gap-3 rounded-md border border-border px-3 py-2 text-left transition-colors hover:bg-secondary hover:text-secondary-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                                    >
                                        <DriverSummary
                                            name={driver.name}
                                            imageUrl={driver.imageUrl}
                                            modes={driver.modes}
                                            warehouseName={driver.warehouseName}
                                        />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}
