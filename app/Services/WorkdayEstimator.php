<?php

namespace App\Services;

/** Totals a driver's day from its resolved segments (warehouse → tours → warehouse), no start selection. */
class WorkdayEstimator
{
    public function __construct(private readonly TravelTimeService $travel) {}

    /**
     * Sum the connecting legs and tour durations; any unknown value counts 0 and flags the estimate incomplete.
     *
     * @param  array<int, TourSegment>  $segments
     */
    public function total(Coordinate $warehouse, array $segments, ?string $mode = null): WorkdayEstimate
    {
        $totalSeconds = 0;
        $incomplete = false;
        $previous = $warehouse;

        foreach ($segments as $segment) {
            $leg = $this->travel->durationBetween($previous, $segment->start, $mode);
            if ($leg === null) {
                $incomplete = true;
            } else {
                $totalSeconds += $leg;
            }

            if ($segment->durationS === null) {
                $incomplete = true;
            } else {
                $totalSeconds += $segment->durationS;
            }

            $previous = $segment->end;
        }

        $returnLeg = $this->travel->durationBetween($previous, $warehouse, $mode);
        if ($returnLeg === null) {
            $incomplete = true;
        } else {
            $totalSeconds += $returnLeg;
        }

        return new WorkdayEstimate($totalSeconds, $incomplete);
    }
}
