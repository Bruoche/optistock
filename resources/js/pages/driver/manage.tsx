// Driver management page (feature 025): editable identity at the top, a day bar
// (prev/next + date + workday figures + tour-order save), a map of the day's assigned
// tours, and an independently scrolling list of those tours. The whole day is loaded from
// one endpoint per date, so switching days needs no full page reload; every region shows a
// fallback until its data arrives, and a stale response for an abandoned date is discarded.
import { Head, router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { DayBar } from '@/components/driver/day-bar';
import { DayLayer } from '@/components/driver/day-layer';
import { DayMarkers } from '@/components/driver/day-markers';
import { DriverIdentityBar } from '@/components/driver/driver-identity-bar';
import { TourList } from '@/components/driver/tour-list';
import { TourMap } from '@/components/tour/tour-map';
import { ConfirmDialog } from '@/components/ui/confirm-dialog';
import { useDayGeometry } from '@/hooks/use-day-geometry';
import { useDriverDay } from '@/hooks/use-driver-day';
import { postJson } from '@/lib/http';
import { todayDate } from '@/types/tour';
import type { DayTour, WarehouseOption } from '@/types/driver';
import type { Stop } from '@/types/tour';

type DriverManageProps = {
    driverId: number;
    initialDate: string;
    warehouses: WarehouseOption[];
    /** True right after returning from a tour edit (feature 025): recompute the day once. */
    recomputeOnLoad?: boolean;
};

export default function DriverManage({
    driverId,
    initialDate,
    warehouses,
    recomputeOnLoad = false,
}: DriverManageProps) {
    const [date, setDate] = useState<string>(initialDate || todayDate());
    const [selectedTourId, setSelectedTourId] = useState<number | null>(null);
    // Bumped after a save so the day re-fetches (a new warehouse/order changes the day).
    const [reloadToken, setReloadToken] = useState(0);
    // The pending drag order (null = the server order); the "Tour order" save persists it.
    const [orderedIds, setOrderedIds] = useState<number[] | null>(null);
    const [savingOrder, setSavingOrder] = useState(false);
    const [orderBlocked, setOrderBlocked] = useState(false);
    // An action requested while an order is unsaved (change day, or edit a tour) — confirm
    // discarding the order first (FR-041).
    const [pendingAction, setPendingAction] = useState<
        { kind: 'date'; date: string } | { kind: 'edit'; tourId: number } | null
    >(null);

    const { day, status } = useDriverDay(driverId, date, reloadToken);
    const legs = useMemo(() => day?.legs ?? [], [day]);
    const tracedLegs = useDayGeometry(
        legs,
        day?.mode ?? null,
        `${driverId}:${date}`,
    );

    const serverTours = day?.tours ?? [];
    // Displayed order: the pending drag order when present, else the server order.
    const tours: DayTour[] = orderedIds
        ? (orderedIds
              .map((id) => serverTours.find((tour) => tour.id === id))
              .filter(Boolean) as DayTour[])
        : serverTours;
    const orderDirty = orderedIds !== null;

    function applyDate(next: string) {
        setSelectedTourId(null);
        setOrderedIds(null);
        setOrderBlocked(false);
        setDate(next);
    }

    function changeDate(next: string) {
        if (orderDirty) {
            setPendingAction({ kind: 'date', date: next });

            return;
        }

        applyDate(next);
    }

    function openEdit(tourId: number) {
        const query = `return_to_driver=${driverId}&return_to_date=${date}`;
        router.visit(`/tour/${tourId}/edit?${query}`);
    }

    function editTour(tourId: number) {
        if (orderDirty) {
            setPendingAction({ kind: 'edit', tourId });

            return;
        }

        openEdit(tourId);
    }

    function toggleTour(tourId: number) {
        setSelectedTourId((current) => (current === tourId ? null : tourId));
    }

    function reorder(ids: number[]) {
        setOrderBlocked(false);
        setOrderedIds(ids);
    }

    async function saveOrder(force: boolean) {
        if (orderedIds === null) {
            return;
        }

        setSavingOrder(true);

        try {
            const response = await postJson(
                `/api/driver/${driverId}/tour-order`,
                { date, tour_ids: orderedIds, force },
            );

            if (response.ok) {
                setOrderedIds(null);
                setOrderBlocked(false);
                setSelectedTourId(null);
                setReloadToken((token) => token + 1);
                toast.success('Tour order saved.');

                return;
            }

            if (response.status === 409) {
                setOrderedIds(null);
                setOrderBlocked(false);
                setReloadToken((token) => token + 1);
                toast.error(
                    'This day changed elsewhere — it has been refreshed.',
                );

                return;
            }

            if (response.status === 422) {
                setOrderBlocked(true);
                toast.error(
                    'A drive on this order could not be routed. Force the save to keep it.',
                );

                return;
            }

            toast.error('Could not save the tour order.');
        } catch {
            toast.error('Network error — please try again.');
        } finally {
            setSavingOrder(false);
        }
    }

    // After returning from a tour edit (025), recompute the day's entry/exit once so the
    // edited tour's assignment stays consistent — a tour-order save of the current order.
    const recomputed = useRef(false);

    useEffect(() => {
        if (
            !recomputeOnLoad ||
            recomputed.current ||
            status !== 'ready' ||
            serverTours.length === 0
        ) {
            return;
        }

        recomputed.current = true;
        // Drop ?recompute from the URL so a manual reload does not recompute again.
        window.history.replaceState({}, '', `/driver/${driverId}?date=${date}`);

        void (async () => {
            try {
                const response = await postJson(
                    `/api/driver/${driverId}/tour-order`,
                    { date, tour_ids: serverTours.map((tour) => tour.id) },
                );

                if (response.ok || response.status === 409) {
                    setReloadToken((token) => token + 1);

                    return;
                }

                if (response.status === 422) {
                    toast.error(
                        'Tour saved, but a drive could not be routed — reorder to force the update.',
                    );

                    return;
                }

                toast.error('Tour saved, but the day could not be recomputed.');
            } catch {
                toast.error('Tour saved, but the day could not be recomputed.');
            }
        })();
    }, [recomputeOnLoad, status, serverTours, driverId, date]);

    const selectedIndex = tours.findIndex((tour) => tour.id === selectedTourId);
    const selectedTour = selectedIndex >= 0 ? tours[selectedIndex] : null;

    // The selected tour's numbered stop markers (reusing the map's primary markers) and
    // its road path — shown only while a tour is selected (FR-019/020).
    const selectedStops: Stop[] = selectedTour
        ? selectedTour.stops.map((stop) => ({
              id: `${selectedTour.id}-${stop.index}`,
              lat: stop.lat,
              lng: stop.lng,
              durationMinutes: 0,
          }))
        : [];

    return (
        <>
            <Head title="Manage driver" />

            <div className="flex min-h-0 flex-1 flex-col">
                <div className="border-b border-border">
                    <DriverIdentityBar
                        driver={day?.driver ?? null}
                        status={status}
                        warehouses={warehouses}
                        onSaved={() => setReloadToken((token) => token + 1)}
                    />
                </div>

                <div className="min-h-0 flex-[2] overflow-hidden">
                    <TourMap stops={selectedStops} addable={false}>
                        <DayLayer
                            legs={tracedLegs}
                            selectedTourIndex={
                                selectedIndex >= 0 ? selectedIndex : null
                            }
                        />
                        {day && (
                            <DayMarkers
                                warehouseCoordinate={
                                    day.driver.warehouseCoordinate
                                }
                                tours={tours}
                            />
                        )}
                    </TourMap>
                </div>

                <DayBar
                    date={date}
                    onDateChange={changeDate}
                    workday={day?.workday ?? null}
                    orderDirty={orderDirty}
                    savingOrder={savingOrder}
                    orderBlocked={orderBlocked}
                    onSaveOrder={() => saveOrder(false)}
                    onForceSaveOrder={() => saveOrder(true)}
                />

                <div className="flex min-h-0 flex-1 flex-col overflow-hidden border-t border-border max-md:overflow-y-auto">
                    <TourList
                        tours={tours}
                        status={status}
                        selectedTourId={selectedTourId}
                        onSelect={toggleTour}
                        onEdit={editTour}
                        onReorder={reorder}
                    />
                </div>
            </div>

            <ConfirmDialog
                open={pendingAction !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setPendingAction(null);
                    }
                }}
                title="Discard the unsaved order?"
                description="You have an unsaved tour order. Leaving this day will discard it."
                confirmLabel="Discard"
                onConfirm={() => {
                    if (pendingAction?.kind === 'date') {
                        applyDate(pendingAction.date);
                    } else if (pendingAction?.kind === 'edit') {
                        openEdit(pendingAction.tourId);
                    }

                    setPendingAction(null);
                }}
            />
        </>
    );
}
