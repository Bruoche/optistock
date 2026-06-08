// Feature 003: delivery-mode dropdown. Lets the planner pick the travel mode
// (trucking by default) that drives both the tour optimization and the road
// path. Lives in the editing-view control bar, to the left of the Optimize
// button; disabled while a tour is optimizing.
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
            <SelectTrigger aria-label="Delivery mode" className="w-40">
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
