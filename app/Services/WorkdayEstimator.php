<?php

namespace App\Services;

/**
 * Totals a driver's working day from its **resolved segments** (feature 013): the drive
 * from the warehouse to the first tour's start, each tour's own duration, the drive
 * between one tour's end and the next tour's start, and the drive from the last tour's
 * end back to the warehouse.
 *
 * It performs **no start selection** — every segment already carries a start and end
 * (see {@see TourStartSelector}) — so it is a pure function of the warehouse plus the
 * ordered segments, reusable to total an already-assigned driver's day with no
 * prospective tour to place (FR-016).
 *
 * Any unknown value contributes 0 and flags the estimate **incomplete** (a lower bound):
 * a connecting leg that could not be routed, or a segment whose own duration is unknown.
 */
class WorkdayEstimator
{
    public function __construct(private readonly TravelTimeService $travel) {}

    /**
     * @param  array<int, TourSegment>  $segments  in the order the driver runs them
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
