// Tour optimization screen (FR-009): interactive map across the top ~2/3, the
// stop list / result in the lower third, and a bottom loading bar while a tour is
// being optimized. Once a result is shown, road geometry (feature 002) is fetched
// and replaces the straight lines + refines the estimate.
import { Head, usePage } from '@inertiajs/react';
import { OptimizingBar } from '@/components/tour/optimizing-bar';
import { ResultSummary } from '@/components/tour/result-summary';
import { RouteLayer } from '@/components/tour/route-layer';
import { StopList } from '@/components/tour/stop-list';
import { TourMap } from '@/components/tour/tour-map';
import { useTourGeometry } from '@/hooks/use-tour-geometry';
import { useTourOptimization } from '@/hooks/use-tour-optimization';

export default function TourOptimize() {
    const userId = usePage().props.auth.user.id;
    const { stops, addStop, removeStop, optimize, reset, state } = useTourOptimization(userId);

    const isPending = state.status === 'submitting' || state.status === 'pending';
    const isDone = state.status === 'done';

    // Feature 002: fetch road geometry for the done tour (straight-line fallback first).
    const doneResult = state.status === 'done' ? state.result : null;
    const geometry = useTourGeometry(doneResult);

    return (
        <>
            <Head title="Optimize tour" />

            <div className="flex min-h-0 flex-1 flex-col">
                <div className="min-h-0 flex-[2] overflow-hidden">
                    <TourMap stops={stops} onAddStop={addStop} addable={!isPending && !isDone}>
                        {isDone && <RouteLayer path={geometry.routePath} closed={geometry.closed} />}
                    </TourMap>
                </div>

                <div className="min-h-0 flex-1 overflow-hidden border-t border-border p-4">
                    {isDone ? (
                        <ResultSummary result={state.result} roadMetrics={geometry.metrics} onReset={reset} />
                    ) : (
                        <StopList
                            stops={stops}
                            onRemove={removeStop}
                            onOptimize={optimize}
                            locked={isPending}
                        />
                    )}
                </div>

                {isPending && <OptimizingBar />}
            </div>
        </>
    );
}
