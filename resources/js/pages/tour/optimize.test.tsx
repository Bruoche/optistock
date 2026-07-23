import { render, screen } from '@testing-library/react';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import type { OptimizeState } from '@/types/tour';

// Mutable optimization state the mocked hook returns; set per test before render.
const mocks = vi.hoisted(() => ({
    state: { status: 'idle' } as OptimizeState,
    visit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
    router: { visit: mocks.visit },
}));
vi.mock('@/components/tour/tour-map', () => ({
    TourMap: ({ children }: { children?: React.ReactNode }) => (
        <div data-testid="map">{children}</div>
    ),
}));
vi.mock('@/components/tour/route-layer', () => ({
    RouteLayer: () => <div data-testid="route-layer" />,
}));
vi.mock('@/hooks/use-tour-geometry', () => ({
    useTourGeometry: () => ({ routePath: [], closed: true, metrics: null }),
}));
vi.mock('@/hooks/use-tour-optimization', () => ({
    useTourOptimization: () => ({
        stops: [],
        addStop: vi.fn(),
        removeStop: vi.fn(),
        setStopDuration: vi.fn(),
        optimize: vi.fn(),
        forceTour: vi.fn(),
        reset: vi.fn(),
        state: mocks.state,
        waitTimeS: 0,
    }),
}));

// Imported after the vi.mock() calls so the mocks are registered first.
// eslint-disable-next-line import/order
import TourOptimize from './optimize';

beforeAll(() => {
    Element.prototype.hasPointerCapture = vi.fn();
    Element.prototype.releasePointerCapture = vi.fn();
    Element.prototype.scrollIntoView = vi.fn();
});

beforeEach(() => {
    mocks.state = { status: 'idle' };
    mocks.visit.mockClear();
});

const EDIT_WITH_RETURN = {
    id: 1,
    mode: 'driving' as const,
    loop: true,
    stops: [],
    returnTo: { driverId: 7, date: '2026-07-06' },
};

describe('TourOptimize driver-management return (025)', () => {
    it('auto-returns to the driver page once the edit re-optimizes (done)', () => {
        mocks.state = {
            status: 'done',
            result: {
                id: 1,
                ordered_stops: [],
                total_distance_m: 100,
                total_duration_s: 600,
            },
            mode: 'driving',
            loop: true,
        };

        render(<TourOptimize editTour={EDIT_WITH_RETURN} />);

        expect(mocks.visit).toHaveBeenCalledWith('/driver/7?date=2026-07-06');
    });

    it('does not auto-return while the edit is still failing (stays to handle it)', () => {
        mocks.state = {
            status: 'failed',
            error: { code: 'api_error', message: 'nope' },
        };

        render(<TourOptimize editTour={EDIT_WITH_RETURN} />);

        expect(mocks.visit).not.toHaveBeenCalled();
    });

    it('offers a Back action that returns without re-optimizing', () => {
        mocks.state = { status: 'idle' };
        render(<TourOptimize editTour={EDIT_WITH_RETURN} />);

        screen.getByRole('button', { name: /back to driver/i }).click();
        expect(mocks.visit).toHaveBeenCalledWith('/driver/7?date=2026-07-06');
    });

    it('does not return automatically for a normal edit (no return target)', () => {
        mocks.state = {
            status: 'done',
            result: {
                id: 1,
                ordered_stops: [],
                total_distance_m: 100,
                total_duration_s: 600,
            },
            mode: 'driving',
            loop: true,
        };

        render(
            <TourOptimize
                editTour={{ id: 1, mode: 'driving', loop: true, stops: [] }}
            />,
        );

        expect(mocks.visit).not.toHaveBeenCalled();
    });
});

describe('TourOptimize control bar visibility (003)', () => {
    it('shows the mode dropdown + loop toggle + Optimize button while editing', () => {
        mocks.state = { status: 'idle' };
        render(<TourOptimize />);

        expect(
            screen.getByRole('combobox', { name: /delivery mode/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /return to origin/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /optimize route/i }),
        ).toBeInTheDocument();
    });

    it('hides the editing-only loop toggle + Optimize button once a result is displayed (FR-004)', () => {
        mocks.state = {
            status: 'done',
            result: {
                id: 1,
                ordered_stops: [],
                total_distance_m: 100,
                total_duration_s: 600,
            },
            mode: 'trucking',
            loop: true,
        };
        render(<TourOptimize />);

        expect(
            screen.queryByRole('button', { name: /return to origin/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /optimize route/i }),
        ).not.toBeInTheDocument();
    });

    // jsdom evaluates no media queries; guard that the mobile panel-scroll overrides are
    // applied so the bottom panel scrolls as one and goes full-bleed on phones (022).
    it('marks the bottom content panel to scroll and drop padding on mobile', () => {
        mocks.state = { status: 'idle' };
        const { container } = render(<TourOptimize />);

        expect(
            container.querySelector('.max-md\\:overflow-y-auto.max-md\\:p-0'),
        ).not.toBeNull();
    });

    it('shows a result-view mode selector defaulting to the tour optimization mode (016)', () => {
        mocks.state = {
            status: 'done',
            result: {
                id: 1,
                ordered_stops: [],
                total_distance_m: 100,
                total_duration_s: 600,
            },
            mode: 'walking',
            loop: true,
        };
        render(<TourOptimize />);

        expect(
            screen.getByRole('combobox', { name: /delivery mode/i }),
        ).toHaveTextContent('Walking');
    });
});
