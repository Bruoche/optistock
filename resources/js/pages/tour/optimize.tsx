// Tour optimization screen: map on top, the stop list / result below, and a
// control bar (mode dropdown + loop toggle + Optimize) shown while editing. Once a
// result is shown, road geometry (002) replaces the straight lines.
import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { OptimizingBar } from '@/components/tour/optimizing-bar';
import { ResultSummary } from '@/components/tour/result-summary';
import { RouteLayer } from '@/components/tour/route-layer';
import { StopList } from '@/components/tour/stop-list';
import { TourControlBar } from '@/components/tour/tour-control-bar';
import { TourMap } from '@/components/tour/tour-map';
import { WorkdayLayer } from '@/components/tour/workday-layer';
import { WorkdayMarkers } from '@/components/tour/workday-markers';
import { useTourGeometry } from '@/hooks/use-tour-geometry';
import { useTourOptimization } from '@/hooks/use-tour-optimization';
import { useWorkdayPreview } from '@/hooks/use-workday-preview';
import { todayDate } from '@/types/tour';
import type { DeliveryMode, Driver, EditTour } from '@/types/tour';

const MIN_STOPS = 2;

type TourOptimizeProps = {
    /** The tour being edited (feature 020); null/absent when building a fresh tour. */
    editTour?: EditTour | null;
};

export default function TourOptimize({ editTour = null }: TourOptimizeProps) {
    const userId = usePage().props.auth.user.id;
    const {
        stops,
        addStop,
        removeStop,
        setStopDuration,
        optimize,
        reset,
        state,
        waitTimeS,
    } = useTourOptimization(userId, editTour);

    // Defaults apply on first load and are retained across a reset (reset clears the tour, not these).
    // When editing, seed the controls from the tour so it re-optimizes exactly as saved (020).
    const [mode, setMode] = useState<DeliveryMode>(
        editTour?.mode ?? 'trucking',
    );
    const [loop, setLoop] = useState<boolean>(editTour?.loop ?? true);
    // Tour date (011); defaults to today and persists across resets, like mode/loop.
    const [tourDate, setTourDate] = useState<string>(todayDate);
    // Mode chosen in the result view (016); null follows the tour's optimization mode.
    // Switching it reloads the driver list only — the tour is never re-optimized.
    const [presentationMode, setPresentationMode] =
        useState<DeliveryMode | null>(null);
    // The driver whose projected workday is previewed on the map (014); row click
    // toggles, and any change that reloads the driver list clears it (FR-012).
    const [selectedDriver, setSelectedDriver] = useState<Driver | null>(null);

    function toggleDriver(driver: Driver) {
        setSelectedDriver((current) =>
            current?.id === driver.id ? null : driver,
        );
    }

    function changeDate(date: string) {
        setSelectedDriver(null);
        setTourDate(date);
    }

    function changeDriverMode(nextMode: DeliveryMode) {
        setSelectedDriver(null);
        setPresentationMode(nextMode);
    }

    function resetTour() {
        setSelectedDriver(null);
        setPresentationMode(null);
        reset();
    }

    const isPending =
        state.status === 'submitting' || state.status === 'pending';
    const isDone = state.status === 'done';
    const canOptimize = stops.length >= MIN_STOPS && !isPending;

    // Geometry uses the mode + loop the shown tour was optimized with, not the live controls (FR-007).
    const doneResult = state.status === 'done' ? state.result : null;
    const optimizationMode = state.status === 'done' ? state.mode : mode;
    const geometry = useTourGeometry(
        doneResult,
        optimizationMode,
        state.status === 'done' ? state.loop : loop,
    );

    // Driver list + preview follow the result-view mode (016); the tour geometry above
    // stays on the optimization mode — switching never re-optimizes/re-traces it.
    const effectiveDriverMode = presentationMode ?? optimizationMode;

    // Best-available workday legs for the previewed driver (014): straight lines
    // first, road paths as their lazy traces land. Keyed per driver-list load (mode included).
    const previewLegs = useWorkdayPreview(
        selectedDriver,
        effectiveDriverMode,
        `${doneResult?.id ?? 0}:${tourDate}:${effectiveDriverMode}`,
    );

    return (
        <>
            <Head title="Optimize tour" />

            <div className="flex min-h-0 flex-1 flex-col">
                <div className="min-h-0 flex-[2] overflow-hidden">
                    <TourMap
                        stops={stops}
                        onAddStop={addStop}
                        addable={!isPending && !isDone}
                    >
                        {isDone && selectedDriver && (
                            <WorkdayLayer legs={previewLegs} />
                        )}
                        {isDone && selectedDriver && (
                            <WorkdayMarkers driver={selectedDriver} />
                        )}
                        {isDone && (
                            <RouteLayer
                                path={geometry.routePath}
                                closed={geometry.closed}
                            />
                        )}
                    </TourMap>
                </div>

                <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden border-t border-border p-4 max-md:overflow-y-auto max-md:p-0">
                    {isDone ? (
                        <ResultSummary
                            result={state.result}
                            roadMetrics={geometry.metrics}
                            waitTimeS={waitTimeS}
                            driverMode={effectiveDriverMode}
                            onDriverModeChange={changeDriverMode}
                            date={tourDate}
                            selectedDriver={selectedDriver}
                            onSelectDriver={toggleDriver}
                            onDateChange={changeDate}
                            onReset={resetTour}
                            onAssigned={resetTour}
                        />
                    ) : (
                        <>
                            <TourControlBar
                                mode={mode}
                                onModeChange={setMode}
                                loop={loop}
                                onLoopChange={setLoop}
                                date={tourDate}
                                onDateChange={setTourDate}
                                onOptimize={() => optimize(mode, loop)}
                                canOptimize={canOptimize}
                                optimizing={isPending}
                            />
                            <StopList
                                stops={stops}
                                onRemove={removeStop}
                                onDurationChange={setStopDuration}
                                locked={isPending}
                            />
                        </>
                    )}
                </div>

                {isPending && <OptimizingBar />}
            </div>
        </>
    );
}
