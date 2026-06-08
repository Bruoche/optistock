// Tour optimization screen (FR-009): interactive map across the top ~2/3, the
// stop list / result in the lower third, and a bottom loading bar while a tour is
// being optimized. A control bar beneath the map (feature 003) holds the delivery
// mode dropdown + the Optimize button while editing. Once a result is shown, road
// geometry (feature 002) is fetched for the tour's mode and replaces the straight
// lines + refines the estimate.
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
import type { DeliveryMode } from '@/types/tour';

const MIN_STOPS = 2;

export default function TourOptimize() {
    const userId = usePage().props.auth.user.id;
    const { stops, addStop, removeStop, optimize, reset, state } = useTourOptimization(userId);

    // Selected delivery mode (feature 003). Trucking is the first-load default;
    // the selection is retained across a reset within the session.
    const [mode, setMode] = useState<DeliveryMode>('trucking');

    const isPending = state.status === 'submitting' || state.status === 'pending';
    const isDone = state.status === 'done';
    const canOptimize = stops.length >= MIN_STOPS && !isPending;

    // Feature 002/003: fetch road geometry for the done tour, using the mode it
    // was optimized with (straight-line fallback first).
    const doneResult = state.status === 'done' ? state.result : null;
    const geometry = useTourGeometry(doneResult, state.status === 'done' ? state.mode : mode);

    return (
        <>
            <Head title="Optimize tour" />

            <div className="flex min-h-0 flex-1 flex-col">
                <div className="min-h-0 flex-[2] overflow-hidden">
                    <TourMap stops={stops} onAddStop={addStop} addable={!isPending && !isDone}>
                        {isDone && <RouteLayer path={geometry.routePath} closed={geometry.closed} />}
                    </TourMap>
                </div>

                <div className="flex min-h-0 flex-1 flex-col gap-3 overflow-hidden border-t border-border p-4">
                    {isDone ? (
                        <ResultSummary result={state.result} roadMetrics={geometry.metrics} onReset={reset} />
                    ) : (
                        <>
                            <TourControlBar
                                mode={mode}
                                onModeChange={setMode}
                                onOptimize={() => optimize(mode)}
                                canOptimize={canOptimize}
                                optimizing={isPending}
                            />
                            <StopList stops={stops} onRemove={removeStop} locked={isPending} />
                        </>
                    )}
                </div>

                {isPending && <OptimizingBar />}
            </div>
        </>
    );
}
