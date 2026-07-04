import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ConfirmDialog } from './confirm-dialog';

function renderDialog(
    props: Partial<React.ComponentProps<typeof ConfirmDialog>> = {},
) {
    return render(
        <ConfirmDialog
            open
            onOpenChange={() => {}}
            title="Make a new tour?"
            description="Are you sure?"
            onConfirm={() => {}}
            {...props}
        />,
    );
}

describe('ConfirmDialog', () => {
    it('renders the title, description and both buttons', () => {
        renderDialog();

        expect(screen.getByText('Make a new tour?')).toBeInTheDocument();
        expect(screen.getByText('Are you sure?')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /confirm/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /cancel/i }),
        ).toBeInTheDocument();
    });

    it('uses a custom confirm label when given', () => {
        renderDialog({ confirmLabel: 'Drop it' });

        expect(
            screen.getByRole('button', { name: /drop it/i }),
        ).toBeInTheDocument();
    });

    it('calls onConfirm when the confirm button is clicked', () => {
        const onConfirm = vi.fn();
        renderDialog({ onConfirm });

        fireEvent.click(screen.getByRole('button', { name: /confirm/i }));

        expect(onConfirm).toHaveBeenCalledTimes(1);
    });

    it('closes via onOpenChange(false) on cancel', () => {
        const onOpenChange = vi.fn();
        renderDialog({ onOpenChange });

        fireEvent.click(screen.getByRole('button', { name: /cancel/i }));

        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('disables both buttons while pending', () => {
        renderDialog({ pending: true });

        expect(screen.getByRole('button', { name: /confirm/i })).toBeDisabled();
        expect(screen.getByRole('button', { name: /cancel/i })).toBeDisabled();
    });
});
