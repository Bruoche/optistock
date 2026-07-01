// Feature 011 (+009): the tour's date — an editable day-only field with the
// selected date's weekday named beside it. Lives in the orange options/results bar
// in both the editing and presentation phases; its weekday drives the driver list.
import { Input } from '@/components/ui/input';
import { formatWeekday } from '@/types/tour';

type TourDateFieldProps = {
    /** Selected date, YYYY-MM-DD. */
    date: string;
    onDateChange: (date: string) => void;
};

export function TourDateField({ date, onDateChange }: TourDateFieldProps) {
    return (
        <div className="flex items-center gap-2">
            <Input
                type="date"
                value={date}
                onChange={(event) => onDateChange(event.target.value)}
                aria-label="Date"
                className="w-auto border-text-on-color bg-primary text-text-on-color hover:bg-secondary [&::-webkit-calendar-picker-indicator]:invert"
            />
            <span className="text-sm font-medium text-text-on-color">
                {formatWeekday(date)}
            </span>
        </div>
    );
}
