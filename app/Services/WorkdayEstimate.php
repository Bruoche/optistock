<?php

namespace App\Services;

/** A driver's projected day: the best-effort total seconds, the driving portion (total minus
 *  counted stop time), and whether any value was unknown. */
final class WorkdayEstimate
{
    public function __construct(
        public readonly int $projectedDurationS,
        public readonly int $drivingDurationS,
        public readonly bool $incomplete,
    ) {}
}
