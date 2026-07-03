<?php

namespace App\Services;

/**
 * An already-assigned tour reduced to what the workday legs and day total need:
 * its recorded start/end, shape, ordered stop coordinates, and own duration
 * (null = unknown, kept distinct from zero).
 */
final class PriorTourLeg
{
    /**
     * @param  array<int, Coordinate>  $stopCoordinates  ordered by stop position
     */
    public function __construct(
        public readonly Coordinate $start,
        public readonly Coordinate $end,
        public readonly bool $loop,
        public readonly array $stopCoordinates,
        public readonly ?int $durationS,
    ) {}

    public function toSegment(): TourSegment
    {
        return new TourSegment($this->start, $this->end, $this->durationS);
    }
}
