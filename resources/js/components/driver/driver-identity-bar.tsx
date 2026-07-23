// The driver identity block at the top of the management page (feature 025): picture,
// name, supported delivery modes, and assigned warehouse — all editable. The "Update"
// action is disabled until an edit differs from the loaded values; changing the warehouse
// prompts a fixed advisory first (FR-007a). A save leaves existing assignments untouched.
//
// The editable form is keyed by the loaded driver identity so it remounts (re-seeding from
// the fresh values) after a save re-fetches — no state-syncing effect.
import { Car, Loader2, PersonStanding, Truck, UserRound } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ActionButton } from '@/components/action-button';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { Input } from '@/components/ui/input';
import { postForm } from '@/lib/http';
import { cn } from '@/lib/utils';
import { DELIVERY_MODES } from '@/types/tour';
import type { DayDriver, WarehouseOption } from '@/types/driver';
import type { DeliveryMode } from '@/types/tour';
import type { LucideIcon } from 'lucide-react';

const MODE_ICON: Record<DeliveryMode, LucideIcon> = {
    walking: PersonStanding,
    driving: Car,
    trucking: Truck,
};

function sameModeSet(a: DeliveryMode[], b: DeliveryMode[]): boolean {
    return (
        a.length === b.length && [...a].sort().join() === [...b].sort().join()
    );
}

type DriverIdentityBarProps = {
    driver: DayDriver | null;
    status: 'loading' | 'ready' | 'error';
    warehouses: WarehouseOption[];
    /** Called after a successful save so the owner can refresh the day (a new warehouse changes the legs). */
    onSaved?: () => void;
};

export function DriverIdentityBar({
    driver,
    status,
    warehouses,
    onSaved,
}: DriverIdentityBarProps) {
    if (status === 'error') {
        return (
            <div className="flex items-center gap-2 p-4 text-sm text-destructive">
                Could not load this driver.
            </div>
        );
    }

    if (status === 'loading' || driver === null) {
        return (
            <div className="flex items-center gap-2 p-4 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                Loading driver…
            </div>
        );
    }

    return (
        <DriverIdentityForm
            key={`${driver.id}:${driver.name}:${driver.warehouseId}:${driver.modes.join(',')}`}
            driver={driver}
            warehouses={warehouses}
            onSaved={onSaved}
        />
    );
}

function DriverIdentityForm({
    driver,
    warehouses,
    onSaved,
}: {
    driver: DayDriver;
    warehouses: WarehouseOption[];
    onSaved?: () => void;
}) {
    const [name, setName] = useState(driver.name);
    const [warehouseId, setWarehouseId] = useState(driver.warehouseId);
    const [modes, setModes] = useState<DeliveryMode[]>(driver.modes);
    const [imageFile, setImageFile] = useState<File | null>(null);
    const [saving, setSaving] = useState(false);
    const [confirmingWarehouse, setConfirmingWarehouse] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const warehouseChanged = warehouseId !== driver.warehouseId;
    const dirty =
        name !== driver.name ||
        warehouseChanged ||
        !sameModeSet(modes, driver.modes) ||
        imageFile !== null;

    function toggleMode(mode: DeliveryMode) {
        setModes((current) =>
            current.includes(mode)
                ? current.filter((value) => value !== mode)
                : [...current, mode],
        );
    }

    async function save() {
        setSaving(true);
        setError(null);

        const form = new FormData();
        form.append('_method', 'PATCH');
        form.append('name', name);
        form.append('warehouse_id', String(warehouseId));
        modes.forEach((mode) => form.append('modes[]', mode));

        if (imageFile) {
            form.append('image', imageFile);
        }

        try {
            const response = await postForm(`/api/driver/${driver.id}`, form);

            if (response.ok) {
                setImageFile(null);
                toast.success('Driver updated.');
                onSaved?.();

                return;
            }

            if (response.status === 422) {
                const payload = await response.json();
                const first = Object.values(
                    (payload.errors ?? {}) as Record<string, string[]>,
                )[0]?.[0];
                setError(first ?? 'Please check the fields.');

                return;
            }

            setError('Could not save the driver.');
        } catch {
            setError('Network error — please try again.');
        } finally {
            setSaving(false);
        }
    }

    function submit() {
        if (!dirty || saving) {
            return;
        }

        if (warehouseChanged) {
            setConfirmingWarehouse(true);

            return;
        }

        void save();
    }

    return (
        <div className="flex flex-wrap items-center gap-4 p-4">
            <label
                className="shrink-0 cursor-pointer"
                aria-label="Driver picture"
            >
                {driver.imageUrl && !imageFile ? (
                    <img
                        src={driver.imageUrl}
                        alt=""
                        className="size-12 rounded-full object-cover"
                    />
                ) : (
                    <span className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <UserRound className="size-6" />
                    </span>
                )}
                <input
                    type="file"
                    accept="image/*"
                    className="hidden"
                    onChange={(event) =>
                        setImageFile(event.target.files?.[0] ?? null)
                    }
                />
            </label>

            <Input
                value={name}
                onChange={(event) => setName(event.target.value)}
                aria-label="Driver name"
                className="w-48"
            />

            <div className="flex items-center gap-1">
                {DELIVERY_MODES.map((mode) => {
                    const Icon = MODE_ICON[mode.value];
                    const active = modes.includes(mode.value);

                    return (
                        <button
                            key={mode.value}
                            type="button"
                            aria-label={mode.label}
                            aria-pressed={active}
                            onClick={() => toggleMode(mode.value)}
                            className={cn(
                                'flex items-center gap-1 rounded-md border px-2 py-1 text-sm transition-colors',
                                active
                                    ? 'border-primary bg-secondary text-secondary-foreground'
                                    : 'border-border text-muted-foreground hover:bg-secondary',
                            )}
                        >
                            <Icon className="size-4" />
                        </button>
                    );
                })}
            </div>

            <select
                value={warehouseId}
                onChange={(event) => setWarehouseId(Number(event.target.value))}
                aria-label="Warehouse"
                className="h-9 rounded-md border border-border bg-background px-2 text-sm"
            >
                {warehouses.map((warehouse) => (
                    <option key={warehouse.id} value={warehouse.id}>
                        {warehouse.name}
                    </option>
                ))}
            </select>

            <div className="ml-auto flex items-center gap-3">
                {error && (
                    <span className="text-sm text-destructive">{error}</span>
                )}
                <ActionButton disabled={!dirty || saving} onClick={submit}>
                    {saving && <Loader2 className="size-4 animate-spin" />}
                    Update
                </ActionButton>
            </div>

            <ConfirmDialog
                open={confirmingWarehouse}
                onOpenChange={setConfirmingWarehouse}
                title="Change this driver's warehouse?"
                description="Changing the warehouse may affect this driver's existing assignments — their days will be recalculated from the new warehouse."
                confirmLabel="Save changes"
                pending={saving}
                onConfirm={() => {
                    setConfirmingWarehouse(false);
                    void save();
                }}
            />
        </div>
    );
}
