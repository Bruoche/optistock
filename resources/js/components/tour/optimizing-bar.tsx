// FR-013: small horizontal status bar shown at the bottom while a tour is being
// optimized. Reuses the shared spinner.
import { Spinner } from '@/components/ui/spinner';

export function OptimizingBar() {
    return (
        <div
            role="status"
            aria-live="polite"
            className="flex items-center justify-center gap-2 border-t border-border bg-secondary px-4 py-2 text-sm font-medium text-text-on-color"
        >
            <Spinner />
            <span>Optimizing…</span>
        </div>
    );
}
