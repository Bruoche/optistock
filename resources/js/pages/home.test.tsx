import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import Home from './home';
import type { ReactNode } from 'react';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: { children: ReactNode }) => (
        <a {...props}>{children}</a>
    ),
}));

describe('Home (dashboard)', () => {
    it('shows the Dashboard title and exactly two tiles', () => {
        render(<Home />);

        expect(
            screen.getByRole('heading', { name: 'Dashboard' }),
        ).toBeInTheDocument();
        expect(screen.getAllByRole('link')).toHaveLength(2);
    });

    it('links New Tour to the tour page with a map illustration', () => {
        render(<Home />);

        const newTour = screen.getByRole('link', { name: /New Tour/ });
        expect(newTour).toHaveAttribute('href', '/tour');
        expect(newTour.querySelector('svg')).not.toBeNull();
    });

    it('links Manage drivers to the drivers directory', () => {
        render(<Home />);

        expect(
            screen.getByRole('link', { name: /Manage drivers/ }),
        ).toHaveAttribute('href', '/driver');
    });
});
