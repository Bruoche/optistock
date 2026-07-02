<?php

namespace App\Services;

/**
 * The result of totalling a driver's day (feature 013): the best-effort projected
 * seconds and whether any value was unknown. `incomplete` true means the figure is a
 * lower bound — a connecting leg failed to route or a tour's own duration was unknown.
 */
final class WorkdayEstimate
{
    public function __construct(
        public readonly int $projectedDurationS,
        public readonly bool $incomplete,
    ) {}
}
