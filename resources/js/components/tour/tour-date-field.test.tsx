import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TourDateField } from './tour-date-field';

describe('TourDateField', () => {
    it('shows the date value and its weekday name (2026-07-06 → Monday)', () => {
        render(<TourDateField date="2026-07-06" onDateChange={() => {}} />);

        expect(screen.getByLabelText('Date')).toHaveValue('2026-07-06');
        expect(screen.getByText('Monday')).toBeInTheDocument();
    });

    it('calls onDateChange with the new value when edited', () => {
        const onDateChange = vi.fn();
        render(<TourDateField date="2026-07-06" onDateChange={onDateChange} />);

        fireEvent.change(screen.getByLabelText('Date'), {
            target: { value: '2026-07-04' },
        });

        expect(onDateChange).toHaveBeenCalledWith('2026-07-04');
    });

    it('updates the weekday label when the date changes (Saturday), no off-by-one', () => {
        const { rerender } = render(
            <TourDateField date="2026-07-06" onDateChange={() => {}} />,
        );
        expect(screen.getByText('Monday')).toBeInTheDocument();

        rerender(<TourDateField date="2026-07-04" onDateChange={() => {}} />);
        expect(screen.getByText('Saturday')).toBeInTheDocument();
        expect(screen.queryByText('Monday')).not.toBeInTheDocument();
    });
});
