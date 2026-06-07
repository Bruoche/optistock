// Tour optimization screen (FR-009): interactive map across the top ~2/3, the
// stop list / result in the lower third, and a bottom loading bar while a tour is
// being optimized.
import { Head, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { OptimizingBar } from '@/components/tour/optimizing-bar';
import { ResultSummary } from '@/components/tour/result-summary';
import { RouteLayer } from '@/components/tour/route-layer';
import { StopList } from '@/components/tour/stop-list';
import { TourMap } from '@/components/tour/tour-map';
import { useTourOptimization } from '@/hooks/use-tour-optimization';
import type { RoutePath } from '@/types/tour';

export default function TourOptimize() {
    const userId = usePage().props.auth.user.id;
    const { stops, addStop, removeStop, optimize, reset, state } = useTourOptimization(userId);

    const isPending = state.status === 'submitting' || state.status === 'pending';
    const isDone = state.status === 'done';

    const routePath = useMemo<RoutePath>(() => {
        if (state.status !== 'done') {
            return [];
        }

        return [...state.result.ordered_stops]
            .sort((a, b) => a.order - b.order)
            .map(({ lat, lng }) => ({ lat, lng }));
    }, [state]);

    return (
        <>
            <Head title="Optimize tour" />

            <div className="flex min-h-0 flex-1 flex-col">
                <div className="min-h-0 flex-[2] overflow-hidden">
                    <TourMap stops={stops} onAddStop={addStop} addable={!isPending && !isDone}>
                        {isDone && <RouteLayer path={routePath} />}
                    </TourMap>
                </div>

                <div className="min-h-0 flex-1 overflow-hidden border-t border-border p-4">
                    {isDone ? (
                        <ResultSummary result={state.result} onReset={reset} />
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
