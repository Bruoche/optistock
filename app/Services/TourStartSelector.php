<?php

namespace App\Services;

use App\Models\Tour;

/** Chooses a tour's start stop as the closest valid one to a caller-supplied incoming point. */
class TourStartSelector
{
    public function __construct(private readonly TravelTimeService $travelTime) {}

    /** Pick the start candidate closest to the incoming point (lowest position when unknown) and deduce its end. */
    public function select(Coordinate $incoming, Tour $tour, ?string $mode = null): TourStart
    {
        $startCandidates = $tour->startCandidates();

        $closestStop = null;
        $closestDuration = null;
        foreach ($startCandidates as $stop) {
            $duration = $this->travelTime->durationBetween($incoming, $stop->coordinate, $mode);
            if ($duration !== null && ($closestDuration === null || $duration < $closestDuration)) {
                $closestDuration = $duration;
                $closestStop = $stop;
            }
        }

        $closestStop ??= $startCandidates->first();

        $endStop = $tour->endStopForStart($closestStop);

        return new TourStart($closestStop->position, $closestStop->coordinate, $endStop->coordinate);
    }
}
