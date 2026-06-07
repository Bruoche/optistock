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
    it('disables Optimize with fewer than two stops (FR-011)', () => {
        render(<StopList stops={[STOPS[0]]} onRemove={noop} onOptimize={noop} />);
        expect(screen.getByRole('button', { name: /optimize route/i })).toBeDisabled();
    });

    it('enables Optimize with two or more stops and fires the callback', () => {
        const onOptimize = vi.fn();
        render(<StopList stops={STOPS} onRemove={noop} onOptimize={onOptimize} />);

        const button = screen.getByRole('button', { name: /optimize route/i });
        expect(button).toBeEnabled();
        fireEvent.click(button);
        expect(onOptimize).toHaveBeenCalledOnce();
    });

    it('removes the matching stop (FR-002 / FR-010)', () => {
        const onRemove = vi.fn();
        render(<StopList stops={STOPS} onRemove={onRemove} onOptimize={noop} />);

        fireEvent.click(screen.getByRole('button', { name: /remove stop 1/i }));
        expect(onRemove).toHaveBeenCalledWith('a');
    });

    it('locks the list while optimizing (FR-012)', () => {
        render(<StopList stops={STOPS} onRemove={noop} onOptimize={noop} locked />);

        const list = screen.getByRole('list');
        expect(list).toHaveAttribute('aria-disabled', 'true');
        expect(list.className).toContain('pointer-events-none');
        // Optimize is disabled while locked even with enough stops.
        expect(screen.getByRole('button', { name: /optimize route/i })).toBeDisabled();
    });
});
