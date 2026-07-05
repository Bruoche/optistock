// FR-010/011/012 (display) + FR-002 (remove): the editable list of stops beneath
// the map. The Optimize action now lives in the control bar above (feature 003).
// Greyed + non-interactive while a tour is optimizing.
import { Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { MAX_STOP_DURATION_MINUTES } from '@/types/tour';
import type { Stop } from '@/types/tour';

type StopListProps = {
    stops: Stop[];
    onRemove: (id: string) => void;
    /** Edit a stop's delivery duration (minutes) — feature 007. */
    onDurationChange?: (id: string, minutes: number) => void;
    /** True while optimizing — list is shown but locked. */
    locked?: boolean;
};

export function StopList({
    stops,
    onRemove,
    onDurationChange,
    locked = false,
}: StopListProps) {
    return (
        <div className="flex h-full flex-col gap-3 max-md:h-auto">
            <ul
                aria-disabled={locked}
                className={cn(
                    'flex-1 space-y-1 overflow-y-auto max-md:flex-none',
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
                        <span className="flex items-center gap-2">
                            <label className="flex items-center gap-1 text-xs text-muted-foreground">
                                <Input
                                    type="number"
                                    min={0}
                                    max={MAX_STOP_DURATION_MINUTES}
                                    step={1}
                                    value={stop.durationMinutes}
                                    disabled={locked}
                                    aria-label={`Delivery duration for stop ${index + 1} (minutes)`}
                                    className="h-8 w-16"
                                    onChange={(event) =>
                                        onDurationChange?.(
                                            stop.id,
                                            Number.parseInt(
                                                event.target.value,
                                                10,
                                            ),
                                        )
                                    }
                                />
                                min
                            </label>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="hover:bg-primary hover:text-primary-foreground"
                                aria-label={`Remove stop ${index + 1}`}
                                disabled={locked}
                                onClick={() => onRemove(stop.id)}
                            >
                                <Trash2 className="text-destructive" />
                            </Button>
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
