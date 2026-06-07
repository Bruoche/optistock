// FR-010/011/012 (display) + FR-002 (remove): the editable list of stops beneath
// the map, with the Optimize action on top. Greyed + non-interactive while a tour
// is optimizing.
import { Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { Stop } from '@/types/tour';

type StopListProps = {
    stops: Stop[];
    onRemove: (id: string) => void;
    onOptimize: () => void;
    /** True while optimizing — list is shown but locked. */
    locked?: boolean;
};

const MIN_STOPS = 2;

export function StopList({ stops, onRemove, onOptimize, locked = false }: StopListProps) {
    const canOptimize = stops.length >= MIN_STOPS && !locked;

    return (
        <div className="flex h-full flex-col gap-3">
            <Button onClick={onOptimize} disabled={!canOptimize} className="w-full">
                Optimize route
            </Button>

            <ul
                aria-disabled={locked}
                className={cn(
                    'flex-1 space-y-1 overflow-y-auto',
                    locked && 'pointer-events-none opacity-50',
                )}
            >
                {stops.length === 0 && (
                    <li className="py-6 text-center text-sm text-muted-foreground">
                        Click on the map to add stops.
                    </li>
                )}

                {stops.map((stop, index) => (
                    <li
                        key={stop.id}
                        className="flex items-center justify-between rounded-md border border-border px-3 py-2 text-sm"
                    >
                        <span>
                            <span className="mr-2 inline-flex size-5 items-center justify-center rounded-full bg-primary text-xs font-semibold text-text-on-color">
                                {index + 1}
                            </span>
                            {stop.lat.toFixed(5)}, {stop.lng.toFixed(5)}
                        </span>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={`Remove stop ${index + 1}`}
                            disabled={locked}
                            onClick={() => onRemove(stop.id)}
                        >
                            <Trash2 className="text-destructive" />
                        </Button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
