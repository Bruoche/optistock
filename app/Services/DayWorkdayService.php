<?php

namespace App\Services;

use App\Enums\DeliveryMode as DeliveryModeEnum;

/**
 * Totals a driver's already-planned day (feature 025): its single mode (derived from the
 * day's tours, FR-045), driven / stop / break / total seconds. Reuses the projected-workday
 * machinery of feature 013–019 with the day's actual assignments and no candidate tour.
 */
class DayWorkdayService
{
    public function __construct(
        private readonly TravelTimeService $travelTime,
        private readonly WorkdayEstimator $workdayEstimator,
    ) {}

    /**
     * @param  array<int, DayAssignment>  $assignments  in running order
     * @return array{mode: ?string, workday: array{total_seconds: int, driven_seconds: int, stop_seconds: int, break_seconds: int, incomplete: bool}}
     */
    public function summarize(Coordinate $warehouse, array $assignments): array
    {
        $mode = $assignments === [] ? null : $assignments[0]->mode;
        $segments = array_map(fn (DayAssignment $assignment): TourSegment => $assignment->toSegment(), $assignments);

        $this->preloadConnections($warehouse, $segments, $mode);

        $estimate = $this->workdayEstimator->total($warehouse, $segments, $mode);
        $break = $this->breakSecondsFor($estimate, $mode);
        $stopSeconds = array_sum(array_map(fn (DayAssignment $assignment): int => $assignment->stopSecondsS, $assignments));

        return [
            'mode' => $mode,
            'workday' => [
                'total_seconds' => $estimate->projectedDurationS + $break,
                'driven_seconds' => $estimate->drivingDurationS,
                'stop_seconds' => $stopSeconds,
                'break_seconds' => $break,
                'incomplete' => $estimate->incomplete,
            ],
        ];
    }

    /** The day's mandatory rest break; the driving-hours rule is road-transport only (a walked day gets the workday break alone). */
    private function breakSecondsFor(WorkdayEstimate $estimate, ?string $mode): int
    {
        $drivingRuleApplies = $mode !== null && DeliveryModeEnum::from($mode) !== DeliveryModeEnum::Walking;

        return MandatoryBreak::secondsFor($estimate->projectedDurationS, $estimate->drivingDurationS, $drivingRuleApplies);
    }

    /**
     * Warm the cache for every warehouse → chain → warehouse connection in one batch, so
     * the estimate and the legs read cached times rather than issuing per-leg fetches.
     *
     * @param  array<int, TourSegment>  $segments
     */
    private function preloadConnections(Coordinate $warehouse, array $segments, ?string $mode): void
    {
        if ($segments === []) {
            return;
        }

        $connections = [];
        $previous = $warehouse;
        foreach ($segments as $segment) {
            $connections[] = [$previous, $segment->start];
            $previous = $segment->end;
        }
        $connections[] = [$previous, $warehouse];

        $this->travelTime->preload($connections, $mode);
    }
}
