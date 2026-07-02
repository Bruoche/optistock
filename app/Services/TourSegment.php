<?php

namespace App\Services;

/**
 * A tour reduced to what the day-total needs (feature 013): the stop it is entered at,
 * the stop it is left from, and its own duration (road + stops). `durationS` is null
 * when that tour's own duration is unknown (e.g. a 2-point tour with no resolved road
 * time) — an unknown that makes the day a flagged best-effort lower bound.
 */
final class TourSegment
{
    public function __construct(
        public readonly Coordinate $start,
        public readonly Coordinate $end,
        public readonly ?int $durationS,
    ) {}
}
