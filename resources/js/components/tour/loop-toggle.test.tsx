import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { LoopToggle } from './loop-toggle';

const noop = () => {};

function toggle() {
    return screen.getByRole('button', { name: /return to origin/i });
}

describe('LoopToggle', () => {
    it('shows the looped state by default (on → "Loop", pressed)', () => {
        render(<LoopToggle value={true} onChange={noop} />);
        expect(toggle()).toHaveTextContent('Loop');
        expect(toggle()).toHaveAttribute('aria-pressed', 'true');
    });

    it('shows the open state when off ("One-way", not pressed)', () => {
        render(<LoopToggle value={false} onChange={noop} />);
        expect(toggle()).toHaveTextContent('One-way');
        expect(toggle()).toHaveAttribute('aria-pressed', 'false');
    });

    it('fires onChange with the toggled value', () => {
        const onChange = vi.fn();
        render(<LoopToggle value={true} onChange={onChange} />);

        fireEvent.click(toggle());
        expect(onChange).toHaveBeenCalledWith(false);
    });

    it('is disabled while optimizing', () => {
        render(<LoopToggle value={true} onChange={noop} disabled />);
        expect(toggle()).toBeDisabled();
    });
});
