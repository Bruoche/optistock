// FR-014/FR-015: after a result arrives, this replaces the Optimize button row.
// Shows the total tour duration at the top; the space the stop list occupied is
// left empty (reserved for a future drivers list — out of scope here).
import { Button } from '@/components/ui/button';
import type { TourResult } from '@/types/tour';

type ResultSummaryProps = {
    result: TourResult;
    /** Road-accurate metrics (feature 002); when present and non-null they override the initial estimate. */
    roadMetrics?: { distance_m: number | null; duration_s: number | null } | null;
    onReset: () => void;
};

function formatDuration(totalSeconds: number | null): string {
    // 2-point tours have no metrics yet (pending the /route/ endpoint).
    if (totalSeconds === null) {
        return 'Unavailable';
    }

    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.round((totalSeconds % 3600) / 60);

    if (hours > 0) {
        return `${hours} h ${minutes.toString().padStart(2, '0')} min`;
    }

    return `${minutes} min`;
}

export function ResultSummary({ result, roadMetrics, onReset }: ResultSummaryProps) {
    // Prefer the road-accurate duration once available; otherwise the initial estimate.
    const durationS = roadMetrics?.duration_s ?? result.total_duration_s;

    return (
        <div className="flex h-full flex-col gap-3">
            <div className="flex items-center justify-between rounded-md bg-primary px-4 py-3 text-text-on-color">
                <div>
                    <p className="text-xs uppercase tracking-wide">Tour duration</p>
                    <p className="text-lg font-semibold">{formatDuration(durationS)}</p>
                </div>
                <Button
                    variant="secondary"
                    className="bg-accent text-accent-foreground hover:bg-accent/90"
                    onClick={onReset}
                >
                    New tour
                </Button>
            </div>

            {/* Reserved for the future drivers list (out of scope). */}
            <div className="flex-1" />
        </div>
    );
}
