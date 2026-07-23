import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { DriverIdentityBar } from './driver-identity-bar';
import type { DayDriver, WarehouseOption } from '@/types/driver';

vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const warehouses: WarehouseOption[] = [
    { id: 3, name: 'North Depot' },
    { id: 4, name: 'South Depot' },
];

function driver(overrides: Partial<DayDriver> = {}): DayDriver {
    return {
        id: 1,
        name: 'Amelie',
        imageUrl: null,
        modes: ['driving'],
        warehouseId: 3,
        warehouseName: 'North Depot',
        warehouseCoordinate: [48.8, 2.3],
        ...overrides,
    };
}

function renderBar(
    props: Partial<React.ComponentProps<typeof DriverIdentityBar>> = {},
) {
    return render(
        <DriverIdentityBar
            driver={driver()}
            status="ready"
            warehouses={warehouses}
            {...props}
        />,
    );
}

describe('DriverIdentityBar', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('disables Update until a field differs, and re-disables when reverted', () => {
        renderBar();

        const update = screen.getByRole('button', { name: 'Update' });
        expect(update).toBeDisabled();

        const nameInput = screen.getByLabelText('Driver name');
        fireEvent.change(nameInput, { target: { value: 'Amelie B' } });
        expect(update).toBeEnabled();

        fireEvent.change(nameInput, { target: { value: 'Amelie' } });
        expect(update).toBeDisabled();
    });

    it('shows a warehouse-change advisory before saving, and saves on confirm', async () => {
        const fetchMock = vi
            .spyOn(globalThis, 'fetch')
            .mockResolvedValue(
                new Response(JSON.stringify({ data: {} }), { status: 200 }),
            );
        const onSaved = vi.fn();
        renderBar({ onSaved });

        // Change the warehouse → Update enables.
        fireEvent.change(screen.getByLabelText('Warehouse'), {
            target: { value: '4' },
        });
        const update = screen.getByRole('button', { name: 'Update' });
        expect(update).toBeEnabled();

        // Pressing Update opens the advisory rather than saving immediately.
        fireEvent.click(update);
        expect(
            screen.getByText(/may affect this driver's existing assignments/i),
        ).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();

        // Confirm → the save fires.
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(onSaved).toHaveBeenCalled());
    });

    it('keeps the edit and shows an error when the save fails', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({
                    errors: { name: ['A driver name is required.'] },
                }),
                { status: 422 },
            ),
        );
        renderBar();

        fireEvent.change(screen.getByLabelText('Driver name'), {
            target: { value: 'New' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Update' }));

        await waitFor(() =>
            expect(
                screen.getByText('A driver name is required.'),
            ).toBeInTheDocument(),
        );
        // The edit is still on screen and Update is still available for retry.
        expect(screen.getByLabelText('Driver name')).toHaveValue('New');
        expect(screen.getByRole('button', { name: 'Update' })).toBeEnabled();
    });
});
