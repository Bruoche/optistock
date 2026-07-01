// Feature 011 (+009): the tour's date — an editable day-only field, styled to sit
// in the orange bar. `TourDateInput` is the shared styled control; `TourDateField`
// is the compact editing-view control-bar version (input + weekday inline). The
// results view composes `TourDateInput` into a labelled figure of its own.
import { Input } from '@/components/ui/input';
import { formatWeekday } from '@/types/tour';

type TourDateProps = {
    /** Selected date, YYYY-MM-DD. */
    date: string;
    onDateChange: (date: string) => void;
};

export function TourDateInput({ date, onDateChange }: TourDateProps) {
    return (
        <Input
            type="date"
            value={date}
            // The native clear (×) yields an empty value; ignore it so the tour
            // always keeps a valid date (spec FR-004 — the label is never blank).
            onChange={(event) => {
                if (event.target.value) {
                    onDateChange(event.target.value);
                }
            }}
            aria-label="Date"
            className="w-auto border-text-on-color bg-primary text-text-on-color hover:bg-secondary [&::-webkit-calendar-picker-indicator]:invert"
        />
    );
}

export function TourDateField({ date, onDateChange }: TourDateProps) {
    return (
        <div className="flex items-center gap-2">
            <TourDateInput date={date} onDateChange={onDateChange} />
            <span className="text-sm font-medium text-text-on-color">
                {formatWeekday(date)}
            </span>
        </div>
    );
}
