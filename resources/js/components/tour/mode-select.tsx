// Feature 003: delivery-mode dropdown for the editing-view control bar.
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { DELIVERY_MODES, type DeliveryMode } from '@/types/tour';

type ModeSelectProps = {
    value: DeliveryMode;
    onChange: (mode: DeliveryMode) => void;
    disabled?: boolean;
};

export function ModeSelect({ value, onChange, disabled = false }: ModeSelectProps) {
    return (
        <Select value={value} onValueChange={(next) => onChange(next as DeliveryMode)} disabled={disabled}>
            <SelectTrigger 
			    aria-label="Delivery mode"
                className="w-40 bg-primary text-text-on-color hover:bg-primary/90 dark:bg-primary dark:hover:bg-primary/90 [&>svg]:!text-text-on-color [&>svg]:!opacity-100"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {DELIVERY_MODES.map((mode) => (
                    <SelectItem key={mode.value} value={mode.value}>
                        {mode.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
