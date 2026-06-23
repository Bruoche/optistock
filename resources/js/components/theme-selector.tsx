// Feature 005: theme toggle for the sidebar. Cycles light → dark → browser and
// reflects the active mode (icon + label; icon-only when the sidebar is collapsed).
import { Monitor, Moon, Sun } from 'lucide-react';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import {  useAppearance } from '@/hooks/use-appearance';
import type {Appearance} from '@/hooks/use-appearance';

const NEXT: Record<Appearance, Appearance> = {
    light: 'dark',
    dark: 'system',
    system: 'light',
};

const MODE: Record<Appearance, { icon: typeof Sun; label: string }> = {
    light: { icon: Sun, label: 'Light' },
    dark: { icon: Moon, label: 'Dark' },
    system: { icon: Monitor, label: 'Browser' },
};

export function ThemeSelector() {
    const { appearance, updateAppearance } = useAppearance();
    const { icon: Icon, label } = MODE[appearance];

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <SidebarMenuButton
                    onClick={() => updateAppearance(NEXT[appearance])}
                    tooltip={`Theme: ${label}`}
                >
                    <Icon />
                    <span>{label}</span>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
