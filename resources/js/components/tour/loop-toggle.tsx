// Feature 004: tour-shape toggle — closed loop (default) vs open one-way.
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
            className="bg-primary text-text-on-color hover:bg-secondary hover:text-secondary-foreground data-[state=on]:bg-primary data-[state=on]:text-text-on-color data-[state=on]:hover:bg-secondary data-[state=on]:hover:text-secondary-foreground"
        >
            {value ? 'Loop' : 'One-way'}
        </Toggle>
    );
}
