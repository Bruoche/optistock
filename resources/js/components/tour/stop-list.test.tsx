import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { Stop } from '@/types/tour';
import { StopList } from './stop-list';

const STOPS: Stop[] = [
    { id: 'a', lat: 48.1, lng: 2.1 },
    { id: 'b', lat: 48.2, lng: 2.2 },
];

const noop = () => {};

describe('StopList', () => {
    it('lists the stops in order', () => {
        render(<StopList stops={STOPS} onRemove={noop} />);
        expect(screen.getAllByRole('listitem')).toHaveLength(2);
    });

    it('shows an empty hint with no stops', () => {
        render(<StopList stops={[]} onRemove={noop} />);
        expect(screen.getByText(/click on the map to add stops/i)).toBeInTheDocument();
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
});
