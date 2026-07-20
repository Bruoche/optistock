import { fireEvent, render, screen } from '@testing-library/react';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { TourControlBar } from './tour-control-bar';

// Radix Select (the mode dropdown) needs these jsdom shims to render.
beforeAll(() => {
    Element.prototype.hasPointerCapture = vi.fn();
    Element.prototype.releasePointerCapture = vi.fn();
    Element.prototype.scrollIntoView = vi.fn();
});

function renderBar(
    props: Partial<React.ComponentProps<typeof TourControlBar>> = {},
) {
    return render(
        <TourControlBar
            mode="trucking"
            onModeChange={() => {}}
            loop={true}
            onLoopChange={() => {}}
            date="2026-07-06"
            onDateChange={() => {}}
            onOptimize={() => {}}
            canOptimize={true}
            optimizing={false}
            {...props}
        />,
    );
}

describe('TourControlBar', () => {
    // jsdom has no layout engine, so real reflow can't be measured; guard that the bar
    // wraps its controls onto multiple rows when they overflow (021).
    it('wraps the bar controls instead of overflowing', () => {
        const { container } = renderBar();

        expect(container.querySelector('.flex-wrap.bg-primary')).not.toBeNull();
    });

    // jsdom evaluates no media queries; guard that the bar is flush edge-to-edge on
    // phones (022) — square corners with the panel's mobile full-bleed.
    it('drops the bar rounding on mobile', () => {
        const { container } = renderBar();

        expect(
            container.querySelector('.max-md\\:rounded-none.bg-primary'),
        ).not.toBeNull();
    });

    // --- Manual fallback (feature 024) -----------------------------------

    it('hides the duration field and Force Tour button until optimization has failed', () => {
        renderBar({ showForce: false });

        expect(
            screen.queryByRole('button', { name: /force tour/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByLabelText(/tour drive duration/i),
        ).not.toBeInTheDocument();
    });

    it('reveals the duration field and Force Tour button on failure (FR-001/FR-003)', () => {
        renderBar({ showForce: true, canForce: false });

        expect(
            screen.getByLabelText(/tour drive duration/i),
        ).toBeInTheDocument();
        // Disabled until a valid duration is entered.
        expect(
            screen.getByRole('button', { name: /force tour/i }),
        ).toBeDisabled();
    });

    it('enables Force Tour and fires onForceTour when a valid duration is present', () => {
        const onForceTour = vi.fn();
        renderBar({
            showForce: true,
            canForce: true,
            forceMinutes: 90,
            onForceTour,
        });

        const button = screen.getByRole('button', { name: /force tour/i });
        expect(button).toBeEnabled();

        fireEvent.click(button);
        expect(onForceTour).toHaveBeenCalledTimes(1);
    });

    it('reports edited duration minutes through onForceMinutesChange', () => {
        const onForceMinutesChange = vi.fn();
        renderBar({ showForce: true, forceMinutes: 30, onForceMinutesChange });

        fireEvent.change(screen.getByLabelText(/tour drive duration/i), {
            target: { value: '75' },
        });
        expect(onForceMinutesChange).toHaveBeenCalledWith(75);
    });
});
