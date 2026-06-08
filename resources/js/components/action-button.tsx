// Primary action button shared across views (Optimize route, New tour, …):
// secondary at rest, accent-yellow on hover. Restyle every such button from here.
import type { ComponentProps } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function ActionButton({ className, ...props }: ComponentProps<typeof Button>) {
    return (
        <Button
            variant="secondary"
            className={cn('hover:bg-accent hover:text-accent-foreground', className)}
            {...props}
        />
    );
}
