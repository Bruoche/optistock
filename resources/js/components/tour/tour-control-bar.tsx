// Feature 003/004/011: editing-view control bar — mode dropdown + loop toggle +
// tour date + the Optimize button.
import { ActionButton } from '@/components/action-button';
import { LoopToggle } from '@/components/tour/loop-toggle';
import { ModeSelect } from '@/components/tour/mode-select';
import { TourDateField } from '@/components/tour/tour-date-field';
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
            <ActionButton onClick={onOptimize} disabled={!canOptimize}>
                Optimize route
            </ActionButton>
        </div>
    );
}
