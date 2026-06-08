// Feature 003/004: the editing-view control bar beneath the map. Holds the
// delivery mode dropdown + the loop toggle on the left and the Optimize
// ("validate") button to its right. Shown only while editing — once a tour result
// is displayed it is replaced by the result view.
import { LoopToggle } from '@/components/tour/loop-toggle';
import { ModeSelect } from '@/components/tour/mode-select';
import { Button } from '@/components/ui/button';
import type { DeliveryMode } from '@/types/tour';

type TourControlBarProps = {
    mode: DeliveryMode;
    onModeChange: (mode: DeliveryMode) => void;
    /** Tour shape: true = closed loop (return to origin), false = open one-way (004). */
    loop: boolean;
    onLoopChange: (loop: boolean) => void;
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
    onOptimize,
    canOptimize,
    optimizing,
}: TourControlBarProps) {
    return (
        <div className="flex items-center gap-3">
            <ModeSelect value={mode} onChange={onModeChange} disabled={optimizing} />
            <LoopToggle value={loop} onChange={onLoopChange} disabled={optimizing} />
            <Button onClick={onOptimize} disabled={!canOptimize} className="flex-1">
                Optimize route
            </Button>
        </div>
    );
}
