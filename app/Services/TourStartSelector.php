<?php

namespace App\Services;

use App\Models\Tour;

/** Chooses a tour's start stop as the closest valid one to a caller-supplied incoming point. */
class TourStartSelector
{
    public function __construct(private readonly TravelTimeService $travel) {}

    /** Pick the nearest known start candidate to the incoming point and deduce its end. */
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

        $chosen ??= $candidates->first();

        $end = $candidate->endStopForStart($chosen);

        return new TourStart($chosen->position, $chosen->coordinate, $end->coordinate);
    }
}
