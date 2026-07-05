import { render } from '@testing-library/react';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { TourControlBar } from './tour-control-bar';

// Radix Select (the mode dropdown) needs these jsdom shims to render.
beforeAll(() => {
    Element.prototype.hasPointerCapture = vi.fn();
    Element.prototype.releasePointerCapture = vi.fn();
    Element.prototype.scrollIntoView = vi.fn();
});

function renderBar() {
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
});
