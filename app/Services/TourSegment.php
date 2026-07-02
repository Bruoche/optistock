<?php

namespace App\Services;

/** A tour reduced to what the day total needs: its start, end, and own duration (null = unknown). */
final class TourSegment
{
    public function __construct(
        public readonly Coordinate $start,
        public readonly Coordinate $end,
        public readonly ?int $durationS,
    ) {}
}
