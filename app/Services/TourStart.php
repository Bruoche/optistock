<?php

namespace App\Services;

/** The start chosen for a tour: the stop's position plus the resolved start/end coordinates. */
final class TourStart
{
    public function __construct(
        public readonly int $startIndex,
        public readonly Coordinate $start,
        public readonly Coordinate $end,
    ) {}
}
