import { fireEvent, render, screen } from '@testing-library/react';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { ModeSelect } from './mode-select';

// Radix Select relies on pointer-capture + scrollIntoView, which jsdom lacks.
beforeAll(() => {
    Element.prototype.hasPointerCapture = vi.fn();
    Element.prototype.releasePointerCapture = vi.fn();
    Element.prototype.scrollIntoView = vi.fn();
});

const noop = () => {};

describe('ModeSelect', () => {
    it('shows the current selection (trucking default — FR-003/FR-004)', () => {
        render(<ModeSelect value="trucking" onChange={noop} />);
        expect(screen.getByRole('combobox', { name: /delivery mode/i })).toHaveTextContent('Trucking');
    });

    it('reflects a non-default selection', () => {
        render(<ModeSelect value="walking" onChange={noop} />);
        expect(screen.getByRole('combobox', { name: /delivery mode/i })).toHaveTextContent('Walking');
    });

    it('lists the three modes and fires onChange with the chosen value (FR-002)', () => {
        const onChange = vi.fn();
        render(<ModeSelect value="trucking" onChange={onChange} />);

        fireEvent.click(screen.getByRole('combobox', { name: /delivery mode/i }));

        expect(screen.getAllByRole('option')).toHaveLength(3);
        fireEvent.click(screen.getByRole('option', { name: 'Walking' }));
        expect(onChange).toHaveBeenCalledWith('walking');
    });

    it('is disabled while optimizing', () => {
        render(<ModeSelect value="trucking" onChange={noop} disabled />);
        expect(screen.getByRole('combobox', { name: /delivery mode/i })).toBeDisabled();
    });
});
