// Feature 004: tour-shape toggle. Lets the planner choose whether the optimized
// tour returns to the origin (closed loop, default) or is an open one-way route.
// Lives in the editing-view control bar, right of the mode dropdown; disabled
// while a tour is optimizing.
import { Toggle } from '@/components/ui/toggle';

type LoopToggleProps = {
    /** true = closed loop (return to origin), false = open one-way. */
    value: boolean;
    onChange: (loop: boolean) => void;
    disabled?: boolean;
};

export function LoopToggle({ value, onChange, disabled = false }: LoopToggleProps) {
    return (
        <Toggle
            variant="outline"
            pressed={value}
            onPressedChange={onChange}
            disabled={disabled}
            aria-label="Return to origin"
        >
            {value ? 'Loop' : 'One-way'}
        </Toggle>
    );
}
