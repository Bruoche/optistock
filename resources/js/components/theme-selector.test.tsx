import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SidebarProvider } from '@/components/ui/sidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import { ThemeSelector } from './theme-selector';
import type { Appearance } from '@/hooks/use-appearance';

const mocks = vi.hoisted(() => ({
    appearance: 'system' as Appearance,
    update: vi.fn(),
}));

vi.mock('@/hooks/use-appearance', () => ({
    useAppearance: () => ({
        appearance: mocks.appearance,
        resolvedAppearance: 'light',
        updateAppearance: mocks.update,
    }),
}));

beforeEach(() => {
    mocks.update.mockClear();
});

function renderSelector(appearance: Appearance) {
    mocks.appearance = appearance;

    return render(
        <TooltipProvider>
            <SidebarProvider>
                <ThemeSelector />
            </SidebarProvider>
        </TooltipProvider>,
    );
}

describe('ThemeSelector', () => {
    it('shows the Browser mode by default (appearance = system)', () => {
        renderSelector('system');
        expect(screen.getByText('Browser')).toBeInTheDocument();
    });

    it('shows the Light label when appearance is light', () => {
        renderSelector('light');
        expect(screen.getByText('Light')).toBeInTheDocument();
    });

    it('shows the Dark label when appearance is dark', () => {
        renderSelector('dark');
        expect(screen.getByText('Dark')).toBeInTheDocument();
    });

    it('cycles light → dark → browser → light on click', () => {
        const { rerender } = renderSelector('light');
        fireEvent.click(screen.getByRole('button'));
        expect(mocks.update).toHaveBeenCalledWith('dark');

        mocks.appearance = 'dark';
        rerender(
            <TooltipProvider>
                <SidebarProvider>
                    <ThemeSelector />
                </SidebarProvider>
            </TooltipProvider>,
        );
        fireEvent.click(screen.getByRole('button'));
        expect(mocks.update).toHaveBeenCalledWith('system');

        mocks.appearance = 'system';
        rerender(
            <TooltipProvider>
                <SidebarProvider>
                    <ThemeSelector />
                </SidebarProvider>
            </TooltipProvider>,
        );
        fireEvent.click(screen.getByRole('button'));
        expect(mocks.update).toHaveBeenCalledWith('light');
    });
});
