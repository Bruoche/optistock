// Feature 011: the tour's date on the presentation phase — an editable day-only
// field with the selected date's weekday named beside it. Changing the date drives
// the available-driver refresh (the list filters by this date's weekday).
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
            <label htmlFor="tour-date" className="text-sm font-medium">
                Date
            </label>
            <Input
                id="tour-date"
                type="date"
                value={date}
                onChange={(event) => onDateChange(event.target.value)}
                className="w-auto"
            />
            <span className="text-sm text-muted-foreground">
                {formatWeekday(date)}
            </span>
        </div>
    );
}
