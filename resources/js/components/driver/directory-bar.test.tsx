import { fireEvent, render, screen } from '@testing-library/react';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { DirectoryBar } from './directory-bar';
import type { DirectoryCriteria } from '@/types/driver';

// Radix Select relies on pointer-capture + scrollIntoView, which jsdom lacks.
beforeAll(() => {
    Element.prototype.hasPointerCapture = vi.fn();
    Element.prototype.releasePointerCapture = vi.fn();
    Element.prototype.scrollIntoView = vi.fn();
});

const WAREHOUSES = [
    { id: 3, name: 'North Depot' },
    { id: 4, name: 'South Depot' },
];

function renderBar(
    criteria: Partial<DirectoryCriteria> = {},
    onChange = vi.fn(),
) {
    render(
        <DirectoryBar
            criteria={{ name: '', modes: [], warehouseId: null, ...criteria }}
            warehouses={WAREHOUSES}
            onChange={onChange}
        />,
    );

    return onChange;
}

describe('DirectoryBar', () => {
    it('emits the typed name (US2)', () => {
        const onChange = renderBar();

        fireEvent.change(
            screen.getByRole('searchbox', { name: /search by name/i }),
            {
                target: { value: 'cha' },
            },
        );

        expect(onChange).toHaveBeenCalledWith(
            expect.objectContaining({ name: 'cha' }),
        );
    });

    it('adds a required mode to the existing selection (US3 — all required)', () => {
        const onChange = renderBar({ modes: ['trucking'] });

        fireEvent.click(screen.getByRole('button', { name: 'Driving' }));

        expect(onChange).toHaveBeenCalledWith(
            expect.objectContaining({ modes: ['trucking', 'driving'] }),
        );
    });

    it('removes an already-selected mode when toggled off (US3)', () => {
        const onChange = renderBar({ modes: ['trucking', 'driving'] });

        fireEvent.click(screen.getByRole('button', { name: 'Trucking' }));

        expect(onChange).toHaveBeenCalledWith(
            expect.objectContaining({ modes: ['driving'] }),
        );
    });

    it('marks selected modes as pressed', () => {
        renderBar({ modes: ['driving'] });

        expect(screen.getByRole('button', { name: 'Driving' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
        expect(screen.getByRole('button', { name: 'Walking' })).toHaveAttribute(
            'aria-pressed',
            'false',
        );
    });

    it('emits the chosen warehouse id (US3)', () => {
        const onChange = renderBar();

        fireEvent.click(screen.getByRole('combobox', { name: /warehouse/i }));
        fireEvent.click(screen.getByRole('option', { name: 'North Depot' }));

        expect(onChange).toHaveBeenCalledWith(
            expect.objectContaining({ warehouseId: 3 }),
        );
    });

    it('emits null when "Any warehouse" is chosen (US3)', () => {
        const onChange = renderBar({ warehouseId: 3 });

        fireEvent.click(screen.getByRole('combobox', { name: /warehouse/i }));
        fireEvent.click(screen.getByRole('option', { name: 'Any warehouse' }));

        expect(onChange).toHaveBeenCalledWith(
            expect.objectContaining({ warehouseId: null }),
        );
    });
});
