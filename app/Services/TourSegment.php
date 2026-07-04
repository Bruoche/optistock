<?php

namespace App\Services;

/** A tour reduced to what the day total needs: its start, end, own duration (null = unknown),
 *  and the stop/service seconds within it (always known — used to split driving from stop time). */
final class TourSegment
{
    public function __construct(
        public readonly Coordinate $start,
        public readonly Coordinate $end,
        public readonly ?int $durationS,
        public readonly int $stopSecondsS = 0,
    ) {}
}
