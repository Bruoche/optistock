<?php

namespace App\Services;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Enums\WeekDay as WeekDayEnum;
use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Repositories\DriverTourRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the available-driver rows for a candidate tour: for each driver eligible on
 * the date, it chains the tour onto their already-assigned day, projects the workday
 * (travel + stops + mandatory breaks), and reports the figures the UI shows.
 */
class DriverAvailabilityService
{
    public function __construct(
        private readonly TravelTimeService $travelTime,
        private readonly TourStartSelector $startSelector,
        private readonly WorkdayEstimator $workdayEstimator,
        private readonly WorkdayLegsBuilder $legsBuilder,
        private readonly DriverTourRepository $driverTours,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rowsFor(string $mode, string $date, int $tourId): Collection
    {
        $deliveryMode = DeliveryModeEnum::from($mode);
        $weekday = WeekDayEnum::fromDate(Carbon::parse($date));
        $candidateTour = Tour::with('stops')->findOrFail($tourId);
        $drivers = Driver::available($deliveryMode, $weekday)->get();
        $priorToursByDriver = $this->driverTours->priorToursByDriver($date, $drivers->pluck('id')->all());

        $this->preloadStartConnections($drivers, $priorToursByDriver, $candidateTour, $deliveryMode);
        $workdays = $this->buildWorkdays($drivers, $priorToursByDriver, $candidateTour, $deliveryMode);
        $this->preloadChainConnections($workdays, $deliveryMode);

        return $workdays->map(fn (array $workday): array => $this->buildDriverRow($workday, $deliveryMode))->values();
    }

    /**
     * Each driver's chained day: their prior tours plus the candidate as a final segment,
     * with the selected start/end for the candidate.
     *
     * @param  Collection<int, Driver>  $drivers
     * @param  Collection<int, Collection<int, PriorTourLeg>>  $priorToursByDriver
     * @return Collection<int, array<string, mixed>>
     */
    private function buildWorkdays(Collection $drivers, Collection $priorToursByDriver, Tour $candidateTour, DeliveryModeEnum $mode): Collection
    {
        return $drivers->map(function (Driver $driver) use ($priorToursByDriver, $candidateTour, $mode): array {
            $priorTours = $priorToursByDriver->get($driver->id, collect());
            $incoming = $this->incomingPoint($driver, $priorTours);
            $start = $this->startSelector->select($incoming, $candidateTour, $mode->value);
            $candidateSegment = new TourSegment($start->start, $start->end, $candidateTour->total_duration_s, (int) $candidateTour->stops->sum('duration_s'));

            return [
                'driver' => $driver,
                'prior_tours' => $priorTours,
                'segments' => [...$priorTours->map(fn (PriorTourLeg $tour): TourSegment => $tour->toSegment())->all(), $candidateSegment],
                'start' => $start,
            ];
        });
    }

    /** One available-driver row: identity, projected day (incl. mandatory break), road times, and legs. */
    private function buildDriverRow(array $workday, DeliveryModeEnum $mode): array
    {
        $driver = $workday['driver'];
        $warehouse = $driver->warehouse->coordinate;
        $projectedStart = $workday['start']; // TourStart: the projected tour's selected start/end stops.

        $withCandidate = $this->workdayEstimator->total($warehouse, $workday['segments'], $mode->value);
        $priorOnly = $this->workdayEstimator->total($warehouse, $this->priorSegments($workday), $mode->value);

        $projectedBreak = $this->breakSecondsFor($withCandidate, $mode);
        $priorOnlyBreak = $this->breakSecondsFor($priorOnly, $mode);

        $incoming = $this->incomingPoint($driver, $workday['prior_tours']);
        $legs = $this->legsBuilder->build($warehouse, $workday['prior_tours']->all(), $projectedStart->start, $projectedStart->end, $mode->value);

        return [
            'id' => $driver->id,
            'name' => $driver->name,
            'image_url' => $driver->image_url,
            'modes' => $driver->deliveryModes->pluck('label')->all(),
            'warehouse_name' => $driver->warehouse->name,
            'projected_seconds' => $withCandidate->projectedDurationS + $projectedBreak,
            'projected_incomplete' => $withCandidate->incomplete,
            // The marginal break this tour adds; clamped at 0 since an unroutable candidate leg can make the raw delta negative.
            'added_break' => max(0, $projectedBreak - $priorOnlyBreak),
            // The two connections bracketing the projected tour (017), read from the already-preloaded cache — no extra routing call.
            'time_to_tour' => $this->travelTime->durationBetween($incoming, $projectedStart->start, $mode->value),
            'time_from_tour' => $this->travelTime->durationBetween($projectedStart->end, $warehouse, $mode->value),
            'start_index' => $projectedStart->startIndex,
            'warehouse_coordinate' => [$warehouse->lat, $warehouse->lng],
            'previous_tour_end' => $incoming->isSameAs($warehouse) ? null : [$incoming->lat, $incoming->lng],
            'legs' => array_map(fn (WorkdayLeg $leg): array => $leg->toArray(), $legs),
        ];
    }

    /** The day's mandatory rest break. The driving-hours rule is road-transport only; a walked day gets the workday break alone. */
    private function breakSecondsFor(WorkdayEstimate $estimate, DeliveryModeEnum $mode): int
    {
        $drivingRuleApplies = $mode !== DeliveryModeEnum::Walking;

        return MandatoryBreak::secondsFor($estimate->projectedDurationS, $estimate->drivingDurationS, $drivingRuleApplies);
    }

    /**
     * Preload the connections from each driver's incoming point to every valid start of
     * the candidate tour, so the start selector reads cached times.
     *
     * @param  Collection<int, Driver>  $drivers
     * @param  Collection<int, Collection<int, PriorTourLeg>>  $priorToursByDriver
     */
    private function preloadStartConnections(Collection $drivers, Collection $priorToursByDriver, Tour $candidateTour, DeliveryModeEnum $mode): void
    {
        $startPoints = $candidateTour->startCandidates()->map(fn (Stop $stop): Coordinate => $stop->coordinate);

        $connections = $drivers->flatMap(function (Driver $driver) use ($priorToursByDriver, $startPoints): array {
            $incoming = $this->incomingPoint($driver, $priorToursByDriver->get($driver->id, collect()));

            return $startPoints->map(fn (Coordinate $startPoint): array => [$incoming, $startPoint])->all();
        })->all();

        $this->travelTime->preload($connections, $mode->value);
    }

    /**
     * Preload every warehouse → chain → warehouse connection for both the chained day and
     * the counterfactual prior-only day (019) — the latter only adds the return leg,
     * kept a cache hit rather than a per-row fetch.
     *
     * @param  Collection<int, array<string, mixed>>  $workdays
     */
    private function preloadChainConnections(Collection $workdays, DeliveryModeEnum $mode): void
    {
        $connections = $workdays->flatMap(function (array $workday): array {
            $warehouse = $workday['driver']->warehouse->coordinate;

            return [
                ...$this->connectionsAlongChain($warehouse, $workday['segments']),
                ...$this->connectionsAlongChain($warehouse, $this->priorSegments($workday)),
            ];
        })->all();

        $this->travelTime->preload($connections, $mode->value);
    }

    /**
     * The prior tours of a workday as segments.
     *
     * @return array<int, TourSegment>
     */
    private function priorSegments(array $workday): array
    {
        return $workday['prior_tours']->map(fn (PriorTourLeg $prior): TourSegment => $prior->toSegment())->all();
    }

    /**
     * Where the driver arrives at the candidate tour from: the end of their last prior
     * tour, or their warehouse when the day is empty.
     *
     * @param  Collection<int, PriorTourLeg>  $priorTours
     */
    private function incomingPoint(Driver $driver, Collection $priorTours): Coordinate
    {
        return $priorTours->last()?->end ?? $driver->warehouse->coordinate;
    }

    /**
     * Every connection of a warehouse → segments → warehouse chain.
     *
     * @param  array<int, TourSegment>  $segments
     * @return array<int, array{0: Coordinate, 1: Coordinate}>
     */
    private function connectionsAlongChain(Coordinate $warehouse, array $segments): array
    {
        $connections = [];
        $previous = $warehouse;
        foreach ($segments as $segment) {
            $connections[] = [$previous, $segment->start];
            $previous = $segment->end;
        }
        $connections[] = [$previous, $warehouse];

        return $connections;
    }
}
