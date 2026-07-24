// A dashboard launcher tile (feature 028): a large clickable card linking to a primary workflow.
// One component, reused for every tile so the card presentation lives in a single place.
import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

type DashboardTileProps = {
    label: string;
    href: string;
    /** Optional illustration filling the card body (e.g. the New Tour map). */
    illustration?: ReactNode;
    /** Optional icon shown beside the label when there is no illustration. */
    icon?: LucideIcon;
};

export function DashboardTile({
    label,
    href,
    illustration,
    icon: Icon,
}: DashboardTileProps) {
    return (
        <Link
            href={href}
            className={cn(
                'group flex aspect-video flex-col overflow-hidden rounded-xl border border-border bg-card text-card-foreground transition-colors',
                'hover:border-primary hover:bg-secondary hover:text-secondary-foreground',
                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden',
            )}
        >
            <div className="flex flex-1 items-center justify-center overflow-hidden p-6 text-muted-foreground">
                {illustration ??
                    (Icon ? <Icon className="h-3/5 w-auto" /> : null)}
            </div>
            <div className="flex items-center gap-2 border-t border-border px-4 py-3 font-semibold">
                {illustration && Icon ? <Icon className="size-5" /> : null}
                {label}
            </div>
        </Link>
    );
}
