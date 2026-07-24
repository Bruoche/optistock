import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DriverDirectory from './directory';
import type { DirectoryDriver } from '@/types/driver';
import type { ReactNode } from 'react';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: { children: ReactNode }) => (
        <a {...props}>{children}</a>
    ),
}));

const mockUseDriversDirectory = vi.fn();

vi.mock('@/hooks/use-drivers-directory', () => ({
    useDriversDirectory: () => mockUseDriversDirectory(),
}));

function driver(overrides: Partial<DirectoryDriver> = {}): DirectoryDriver {
    return {
        id: 1,
        name: 'Amelie',
        imageUrl: null,
        modes: ['driving'],
        warehouseId: 3,
        warehouseName: 'North Depot',
        ...overrides,
    };
}

function renderPage() {
    return render(
        <DriverDirectory
            warehouses={[
                { id: 3, name: 'North Depot' },
                { id: 4, name: 'South Depot' },
            ]}
        />,
    );
}

describe('DriverDirectory', () => {
    beforeEach(() => {
        mockUseDriversDirectory.mockReset();
    });

    it('shows a spinner while loading', () => {
        mockUseDriversDirectory.mockReturnValue({
            drivers: [],
            status: 'loading',
        });
        renderPage();
        expect(screen.getByText(/loading drivers/i)).toBeInTheDocument();
    });

    it('shows an error message when the drivers cannot be loaded', () => {
        mockUseDriversDirectory.mockReturnValue({
            drivers: [],
            status: 'error',
        });
        renderPage();
        expect(screen.getByText(/could not load drivers/i)).toBeInTheDocument();
    });

    it('shows the exact empty-state text when nothing matches', () => {
        mockUseDriversDirectory.mockReturnValue({
            drivers: [],
            status: 'ready',
        });
        renderPage();
        expect(
            screen.getByText('no drivers found with current criterias.'),
        ).toBeInTheDocument();
    });

    it('renders each driver with identity, placeholder, and a link to their management page', () => {
        mockUseDriversDirectory.mockReturnValue({
            status: 'ready',
            drivers: [
                driver({
                    id: 1,
                    name: 'Amelie',
                    modes: ['driving', 'walking'],
                }),
                driver({
                    id: 2,
                    name: 'Bruno',
                    imageUrl: '/storage/drivers/b.jpg',
                    warehouseName: 'South Depot',
                }),
            ],
        });

        const { container } = renderPage();

        expect(screen.getByText('Amelie')).toBeInTheDocument();
        expect(screen.getByText('North Depot')).toBeInTheDocument();
        expect(screen.getByText('South Depot')).toBeInTheDocument();

        const amelieRow = screen.getByRole('link', { name: /Amelie/ });
        expect(within(amelieRow).getByLabelText('Walking')).toBeInTheDocument();
        expect(amelieRow).toHaveAttribute('href', '/driver/1');
        expect(screen.getByRole('link', { name: /Bruno/ })).toHaveAttribute(
            'href',
            '/driver/2',
        );

        const images = container.querySelectorAll('img');
        expect(images).toHaveLength(1);
        expect(images[0]).toHaveAttribute('src', '/storage/drivers/b.jpg');
    });
});
