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
import { useTourGeometry } from '@/hooks/use-tour-geometry';
import { useTourOptimization } from '@/hooks/use-tour-optimization';
import { todayDate } from '@/types/tour';
import type { DeliveryMode } from '@/types/tour';

const MIN_STOPS = 2;

export default function TourOptimize() {
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
    } = useTourOptimization(userId);

    // Defaults apply on first load and are retained across a reset (reset clears the tour, not these).
    const [mode, setMode] = useState<DeliveryMode>('trucking');
    const [loop, setLoop] = useState<boolean>(true);
    // Tour date (011); defaults to today and persists across resets, like mode/loop.
    const [tourDate, setTourDate] = useState<string>(todayDate);

    const isPending =
        state.status === 'submitting' || state.status === 'pending';
    const isDone = state.status === 'done';
    const canOptimize = stops.length >= MIN_STOPS && !isPending;

    // Geometry uses the mode + loop the shown tour was optimized with, not the live controls (FR-007).
    const doneResult = state.status === 'done' ? state.result : null;
    const geometry = useTourGeometry(
        doneResult,
        state.status === 'done' ? state.mode : mode,
        state.status === 'done' ? state.loop : loop,
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
                        {isDone && (
                            <RouteLayer
                                path={geometry.routePath}
                                closed={geometry.closed}
                            />
                        )}
                    </TourMap>
                </div>

                <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden border-t border-border p-4">
                    {isDone ? (
                        <ResultSummary
                            result={state.result}
                            roadMetrics={geometry.metrics}
                            waitTimeS={waitTimeS}
                            mode={state.mode}
                            date={tourDate}
                            onDateChange={setTourDate}
                            onReset={reset}
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
