<?php

namespace App\Services;

/**
 * The start chosen for a tour (feature 013): the stop's position (its `start_index`,
 * sent to the client and echoed back on assignment), plus the resolved start and end
 * coordinates (the end deduced from the start per the tour's shape).
 */
final class TourStart
{
    public function __construct(
        public readonly int $startIndex,
        public readonly Coordinate $start,
        public readonly Coordinate $end,
    ) {}
}
