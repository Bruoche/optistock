// The day's tours in running order (feature 025). Scrolls independently of the identity
// block and day bar (FR-028) so they stay visible. Loading/error/empty each show an
// explicit fallback. When reorderable, each row gets a drag handle and the list is a
// vertical sortable context (US4); reordering is reported to the owner, who holds the order.
import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import { restrictToVerticalAxis } from '@dnd-kit/modifiers';
import {
    SortableContext,
    arrayMove,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical, Loader2 } from 'lucide-react';
import { TourRow } from '@/components/driver/tour-row';
import type { DayTour } from '@/types/driver';
import type { DragEndEvent } from '@dnd-kit/core';
import type { ReactNode } from 'react';

function StatusLine({
    children,
    tone = 'muted',
}: {
    children: ReactNode;
    tone?: 'muted' | 'error';
}) {
    return (
        <div
            className={
                tone === 'error'
                    ? 'flex flex-1 items-center justify-center gap-2 p-4 text-sm text-destructive'
                    : 'flex flex-1 items-center justify-center gap-2 p-4 text-sm text-muted-foreground'
            }
        >
            {children}
        </div>
    );
}

function SortableTourRow({
    tour,
    position,
    selected,
    onSelect,
    onEdit,
}: {
    tour: DayTour;
    position: number;
    selected: boolean;
    onSelect: (tourId: number) => void;
    onEdit: (tourId: number) => void;
}) {
    const { attributes, listeners, setNodeRef, transform, transition } =
        useSortable({ id: tour.id });

    const handle = (
        <button
            type="button"
            aria-label={`Reorder Tour ${position}`}
            className="flex cursor-grab touch-none items-center px-2 text-muted-foreground"
            {...attributes}
            {...listeners}
        >
            <GripVertical className="size-4" />
        </button>
    );

    return (
        <TourRow
            tour={tour}
            position={position}
            selected={selected}
            onSelect={onSelect}
            onEdit={onEdit}
            handle={handle}
            rootRef={setNodeRef}
            rootStyle={{
                transform: CSS.Transform.toString(transform),
                transition,
            }}
        />
    );
}

type TourListProps = {
    tours: DayTour[];
    status: 'loading' | 'ready' | 'error';
    selectedTourId: number | null;
    onSelect: (tourId: number) => void;
    /** Opens the tour-edit screen for a row (US5). */
    onEdit: (tourId: number) => void;
    /** When set (and >1 tour), rows are draggable; the new id order is reported here. */
    onReorder?: (orderedTourIds: number[]) => void;
};

export function TourList({
    tours,
    status,
    selectedTourId,
    onSelect,
    onEdit,
    onReorder,
}: TourListProps) {
    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    if (status === 'loading') {
        return (
            <StatusLine>
                <Loader2 className="size-4 animate-spin" />
                Loading tours…
            </StatusLine>
        );
    }

    if (status === 'error') {
        return <StatusLine tone="error">Could not load the day.</StatusLine>;
    }

    if (tours.length === 0) {
        return <StatusLine>No tours assigned this day.</StatusLine>;
    }

    // A single-tour day (or no reorder handler) is not draggable — the handles are inert.
    const reorderable = onReorder !== undefined && tours.length > 1;

    if (!reorderable) {
        return (
            <ul className="min-h-0 flex-1 space-y-1 overflow-y-auto p-4 max-md:flex-none">
                {tours.map((tour, index) => (
                    <TourRow
                        key={tour.id}
                        tour={tour}
                        position={index + 1}
                        selected={tour.id === selectedTourId}
                        onSelect={onSelect}
                        onEdit={onEdit}
                    />
                ))}
            </ul>
        );
    }

    function handleDragEnd(event: DragEndEvent) {
        const { active, over } = event;

        if (over === null || active.id === over.id) {
            return;
        }

        const ids = tours.map((tour) => tour.id);
        const from = ids.indexOf(Number(active.id));
        const to = ids.indexOf(Number(over.id));
        onReorder?.(arrayMove(ids, from, to));
    }

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            modifiers={[restrictToVerticalAxis]}
            onDragEnd={handleDragEnd}
        >
            <SortableContext
                items={tours.map((tour) => tour.id)}
                strategy={verticalListSortingStrategy}
            >
                <ul className="min-h-0 flex-1 space-y-1 overflow-y-auto p-4 max-md:flex-none">
                    {tours.map((tour, index) => (
                        <SortableTourRow
                            key={tour.id}
                            tour={tour}
                            position={index + 1}
                            selected={tour.id === selectedTourId}
                            onSelect={onSelect}
                            onEdit={onEdit}
                        />
                    ))}
                </ul>
            </SortableContext>
        </DndContext>
    );
}
