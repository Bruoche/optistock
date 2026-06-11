import { render, screen } from '@testing-library/react';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import type { OptimizeState } from '@/types/tour';

// Mutable optimization state the mocked hook returns; set per test before render.
const mocks = vi.hoisted(() => ({
    state: { status: 'idle' } as OptimizeState,
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { auth: { user: { id: 7 } } } }),
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
    Element.prototype.scrollIntoView = vi.fn();
});

beforeEach(() => {
    mocks.state = { status: 'idle' };
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

    it('hides the editing controls once a result is displayed (editing-only, FR-004)', () => {
        mocks.state = {
            status: 'done',
            result: {
                ordered_stops: [],
                total_distance_m: 100,
                total_duration_s: 600,
            },
            mode: 'trucking',
            loop: true,
        };
        render(<TourOptimize />);

        expect(
            screen.queryByRole('combobox', { name: /delivery mode/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /return to origin/i }),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /optimize route/i }),
        ).not.toBeInTheDocument();
    });
});
