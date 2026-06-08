// Feature 003/004: editing-view control bar — mode dropdown + loop toggle + the Optimize button.
import { ActionButton } from '@/components/action-button';
import { LoopToggle } from '@/components/tour/loop-toggle';
import { ModeSelect } from '@/components/tour/mode-select';
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
        <div className="flex items-center justify-between rounded-md bg-primary px-4 py-3 text-text-on-color">
            <div>
                <p className="text-xs uppercase tracking-wide">Options</p>
				<div className="flex items-center gap-3">
					<ModeSelect value={mode} onChange={onModeChange} disabled={optimizing} />
					<LoopToggle value={loop} onChange={onLoopChange} disabled={optimizing} />
	            </div>
			</div>
			<ActionButton onClick={onOptimize} disabled={!canOptimize}>
				Optimize route
			</ActionButton>
        </div>
    );
}
