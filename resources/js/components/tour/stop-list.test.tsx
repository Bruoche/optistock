import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StopList } from './stop-list';
import type { Stop } from '@/types/tour';

const STOPS: Stop[] = [
    { id: 'a', lat: 48.1, lng: 2.1, durationMinutes: 10 },
    { id: 'b', lat: 48.2, lng: 2.2, durationMinutes: 20 },
];

const noop = () => {};

describe('StopList', () => {
    it('lists the stops in order', () => {
        render(<StopList stops={STOPS} onRemove={noop} />);
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
    });

    // jsdom evaluates no media queries; guard the mobile panel-scroll overrides (022):
    // the wrapper is content-height and the list is natural height so the panel scrolls as one.
    it('applies the mobile content-height overrides', () => {
        const { container } = render(
            <StopList stops={STOPS} onRemove={noop} />,
        );

        expect(container.firstElementChild?.className).toContain(
            'max-md:h-auto',
        );
        expect(screen.getByRole('list').className).toContain(
            'max-md:flex-none',
        );
    });

    it('shows an empty hint with no stops', () => {
        render(<StopList stops={[]} onRemove={noop} />);
        expect(
            screen.getByText(/click on the map to add stops/i),
        ).toBeInTheDocument();
    });

    it('removes the matching stop (FR-002 / FR-010)', () => {
        const onRemove = vi.fn();
        render(<StopList stops={STOPS} onRemove={onRemove} />);

        fireEvent.click(screen.getByRole('button', { name: /remove stop 1/i }));
        expect(onRemove).toHaveBeenCalledWith('a');
    });

    it('locks the list while optimizing (FR-012)', () => {
        render(<StopList stops={STOPS} onRemove={noop} locked />);

        const list = screen.getByRole('list');
        expect(list).toHaveAttribute('aria-disabled', 'true');
        expect(list.className).toContain('pointer-events-none');
    });

    it('shows a minutes input per row pre-filled with the stop duration (FR-001/FR-002)', () => {
        render(<StopList stops={STOPS} onRemove={noop} />);

        expect(
            screen.getByLabelText(/delivery duration for stop 1/i),
        ).toHaveValue(10);
        expect(
            screen.getByLabelText(/delivery duration for stop 2/i),
        ).toHaveValue(20);
    });

    it('edits only the targeted stop duration (FR-003)', () => {
        const onDurationChange = vi.fn();
        render(
            <StopList
                stops={STOPS}
                onRemove={noop}
                onDurationChange={onDurationChange}
            />,
        );

        fireEvent.change(
            screen.getByLabelText(/delivery duration for stop 2/i),
            {
                target: { value: '25' },
            },
        );
        expect(onDurationChange).toHaveBeenCalledWith('b', 25);
    });

    it('disables the duration input while locked (FR-012)', () => {
        render(<StopList stops={STOPS} onRemove={noop} locked />);
        expect(
            screen.getByLabelText(/delivery duration for stop 1/i),
        ).toBeDisabled();
    });
});
