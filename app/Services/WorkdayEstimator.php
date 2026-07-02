<?php

namespace App\Services;

/** Totals a driver's day from its resolved segments (warehouse → tours → warehouse), no start selection. */
class WorkdayEstimator
{
    public function __construct(private readonly TravelTimeService $travelTime) {}

    /**
     * Best-effort day total: every unknown duration counts 0 and flags the estimate incomplete.
     *
     * @param  array<int, TourSegment>  $segments
     */
    public function total(Coordinate $warehouse, array $segments, ?string $mode = null): WorkdayEstimate
    {
        $durations = $this->chainedDurations($warehouse, $segments, $mode);

        $knownSeconds = (int) array_sum(array_filter($durations, is_int(...)));
        $hasUnknownDuration = in_array(null, $durations, true);

        return new WorkdayEstimate($knownSeconds, $hasUnknownDuration);
    }

    /**
     * Every duration of the chained day in order — connection, tour, connection, tour, …,
     * return connection — where null marks an unknown value.
     *
     * @param  array<int, TourSegment>  $segments
     * @return array<int, int|null>
     */
    private function chainedDurations(Coordinate $warehouse, array $segments, ?string $mode): array
    {
        $durations = [];
        $previous = $warehouse;

        foreach ($segments as $segment) {
            $durations[] = $this->travelTime->durationBetween($previous, $segment->start, $mode);
            $durations[] = $segment->durationS;
            $previous = $segment->end;
        }

        $durations[] = $this->travelTime->durationBetween($previous, $warehouse, $mode);

        return $durations;
    }
}
