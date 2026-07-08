// Feature 003/004/011: editing-view control bar — mode dropdown + loop toggle +
// tour date + the Optimize button. Feature 024: when optimization has failed, a manual
// drive-duration field + Force Tour button appear as the fallback so a dead routing API
// never blocks saving a tour.
import { ActionButton } from '@/components/action-button';
import { LoopToggle } from '@/components/tour/loop-toggle';
import { ModeSelect } from '@/components/tour/mode-select';
import { TourDateField } from '@/components/tour/tour-date-field';
import { Input } from '@/components/ui/input';
import { MAX_TOUR_DURATION_MINUTES } from '@/types/tour';
import type { DeliveryMode } from '@/types/tour';

type TourControlBarProps = {
    mode: DeliveryMode;
    onModeChange: (mode: DeliveryMode) => void;
    /** Tour shape: true = closed loop (return to origin), false = open one-way (004). */
    loop: boolean;
    onLoopChange: (loop: boolean) => void;
    /** Selected tour date (YYYY-MM-DD); its weekday later filters the driver list (011). */
    date: string;
    onDateChange: (date: string) => void;
    onOptimize: () => void;
    /** True when there are enough stops and no optimization is in flight. */
    canOptimize: boolean;
    /** True while a tour is optimizing — locks the mode dropdown + loop toggle. */
    optimizing: boolean;
    /** Reveal the manual drive-duration field + Force Tour button — set only after an
     *  optimization request has failed (feature 024). */
    showForce?: boolean;
    /** The manual drive duration (minutes) entered in the fallback field. */
    forceMinutes?: number;
    onForceMinutesChange?: (minutes: number) => void;
    onForceTour?: () => void;
    /** True when a valid duration is entered and there are enough stops. */
    canForce?: boolean;
};

export function TourControlBar({
    mode,
    onModeChange,
    loop,
    onLoopChange,
    date,
    onDateChange,
    onOptimize,
    canOptimize,
    optimizing,
    showForce = false,
    forceMinutes,
    onForceMinutesChange,
    onForceTour,
    canForce = false,
}: TourControlBarProps) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-4 rounded-md bg-primary px-4 py-3 text-text-on-color max-md:rounded-none">
            <div>
                <p className="text-xs tracking-wide uppercase">Options</p>
                <div className="flex flex-wrap items-center gap-3">
                    <ModeSelect
                        value={mode}
                        onChange={onModeChange}
                        disabled={optimizing}
                    />
                    <LoopToggle
                        value={loop}
                        onChange={onLoopChange}
                        disabled={optimizing}
                    />
                    <TourDateField date={date} onDateChange={onDateChange} />
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-4">
                {showForce && (
                    <label className="flex items-center gap-2 text-xs tracking-wide uppercase">
                        Tour duration
                        <Input
                            type="number"
                            min={1}
                            max={MAX_TOUR_DURATION_MINUTES}
                            step={1}
                            value={forceMinutes ?? ''}
                            aria-label="Tour drive duration (minutes)"
                            className="text-text h-8 w-20"
                            onChange={(event) =>
                                onForceMinutesChange?.(
                                    Number.parseInt(event.target.value, 10),
                                )
                            }
                        />
                        min
                    </label>
                )}
                {showForce && (
                    <ActionButton onClick={onForceTour} disabled={!canForce}>
                        Force Tour
                    </ActionButton>
                )}
                <ActionButton onClick={onOptimize} disabled={!canOptimize}>
                    Optimize route
                </ActionButton>
            </div>
        </div>
    );
}
