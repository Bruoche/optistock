<?php

namespace App\Services;

/** A driver's projected day: the best-effort seconds and whether any value was unknown. */
final class WorkdayEstimate
{
    public function __construct(
        public readonly int $projectedDurationS,
        public readonly bool $incomplete,
    ) {}
}
