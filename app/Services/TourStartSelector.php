<?php

namespace App\Services;

use App\Models\Tour;

/**
 * Chooses the stop a driver enters a tour by (feature 013): the valid start with the
 * shortest road time from the **incoming point** — which the caller supplies (the
 * warehouse for the first tour of the day, the previous tour's end otherwise). This is
 * the only place a start is selected; the day total ({@see WorkdayEstimator}) consumes
 * the resolved start/end without re-selecting.
 *
 * Unknown legs are tolerated: the closest *known* candidate wins; if none can be routed
 * the lowest-position candidate is used (a deterministic fallback). The end is deduced
 * from the chosen start by the tour's shape.
 */
class TourStartSelector
{
    public function __construct(private readonly TravelTimeService $travel) {}

    public function select(Coordinate $incoming, Tour $candidate, ?string $mode = null): TourStart
    {
        $candidates = $candidate->startCandidates();

        $chosen = null;
        $shortest = null;
        foreach ($candidates as $stop) {
            $duration = $this->travel->durationBetween($incoming, $stop->coordinate, $mode);
            if ($duration !== null && ($shortest === null || $duration < $shortest)) {
                $shortest = $duration;
                $chosen = $stop;
            }
        }

        // Every candidate leg was unknown → keep the first (lowest-position) candidate.
        $chosen ??= $candidates->first();

        $end = $candidate->endStopForStart($chosen);

        return new TourStart($chosen->position, $chosen->coordinate, $end->coordinate);
    }
}
