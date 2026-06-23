// Primary action button shared across views (Optimize route, New tour, …):
// secondary at rest, secondary-hover (lighter) on hover. Restyle every such button from here.
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { ComponentProps } from 'react';

export function ActionButton({
    className,
    ...props
}: ComponentProps<typeof Button>) {
    return (
        <Button
            variant="secondary"
            className={cn(
                'hover:bg-secondary-hover hover:text-secondary-foreground',
                className,
            )}
            {...props}
        />
    );
}
