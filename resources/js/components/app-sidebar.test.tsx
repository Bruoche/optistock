import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import { AppSidebar } from './app-sidebar';
import type { ReactNode } from 'react';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        url: '/',
        props: {
            auth: {
                user: { id: 1, name: 'Alice', email: 'alice@example.com' },
            },
            currentTeam: null,
            teams: [],
        },
    }),
    Link: ({ children, ...props }: { children: ReactNode }) => (
        <a {...props}>{children}</a>
    ),
}));

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({
        appearance: 'system',
        resolvedAppearance: 'light',
        updateAppearance: vi.fn(),
    }),
}));

function renderSidebar() {
    return render(
        <TooltipProvider>
            <SidebarProvider>
                <AppSidebar />
            </SidebarProvider>
        </TooltipProvider>,
    );
}

describe('AppSidebar', () => {
    it('shows the Optistock brand', () => {
        renderSidebar();
        expect(screen.getByText('Optistock')).toBeInTheDocument();
    });

    it('keeps the account/user menu', () => {
        const { container } = renderSidebar();
        expect(
            container.querySelector('[data-test="sidebar-menu-button"]'),
        ).not.toBeNull();
    });

    it('shows the theme toggle', () => {
        renderSidebar();
        // Default appearance = system → "Browser" label.
        expect(screen.getByText('Browser')).toBeInTheDocument();
    });

    it('lists the New Tour and Manage drivers navigation links (028)', () => {
        renderSidebar();

        expect(screen.getByRole('link', { name: 'New Tour' })).toHaveAttribute(
            'href',
            '/tour',
        );
        expect(
            screen.getByRole('link', { name: 'Manage drivers' }),
        ).toHaveAttribute('href', '/driver');
    });

    it('removes the starter-kit nav, links, and team switcher', () => {
        const { container } = renderSidebar();
        expect(
            container.querySelector('[data-test="team-switcher-trigger"]'),
        ).toBeNull();
        expect(screen.queryByText('Dashboard')).not.toBeInTheDocument();
        expect(screen.queryByText('Repository')).not.toBeInTheDocument();
        expect(screen.queryByText('Documentation')).not.toBeInTheDocument();
    });
});
