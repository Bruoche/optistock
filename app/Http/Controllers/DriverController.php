<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Enums\WeekDay as WeekDayEnum;
use App\Http\Requests\AvailableDriversRequest;
use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Services\Coordinate;
use App\Services\PriorTourLeg;
use App\Services\TourSegment;
use App\Services\TourStartSelector;
use App\Services\TravelTimeService;
use App\Services\WorkdayEstimator;
use App\Services\WorkdayLeg;
use App\Services\WorkdayLegsBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Lists the drivers available for a tour with each one's projected working day if given it. */
class DriverController extends Controller
{
    /** GET /api/tour/drivers — available drivers plus their chained projected day, chosen start, and workday legs. */
    public function available(
        AvailableDriversRequest $request,
        TravelTimeService $travelTime,
        TourStartSelector $startSelector,
        WorkdayEstimator $workdayEstimator,
        WorkdayLegsBuilder $legsBuilder,
    ): JsonResponse {
        $mode = DeliveryModeEnum::from($request->validated('mode'));
        $date = $request->date('date')->toDateString();
        $weekday = WeekDayEnum::fromDate($request->date('date'));
        $candidateTour = Tour::with('stops')->findOrFail($request->integer('tour'));
        $drivers = Driver::available($mode, $weekday)->get();
        $priorToursByDriver = $this->priorToursByDriver($date, $drivers->pluck('id')->all());

        $travelTime->preload($this->connectionsToStartCandidates($drivers, $priorToursByDriver, $candidateTour), $mode->value);

        $workdays = $drivers->map(function (Driver $driver) use ($priorToursByDriver, $candidateTour, $startSelector, $mode): array {
            $priorTours = $priorToursByDriver->get($driver->id, collect());
            $incoming = $this->incomingPoint($driver, $priorTours);
            $start = $startSelector->select($incoming, $candidateTour, $mode->value);
            $candidateSegment = new TourSegment($start->start, $start->end, $candidateTour->total_duration_s);

            return [
                'driver' => $driver,
                'prior_tours' => $priorTours,
                'segments' => [...$priorTours->map(fn (PriorTourLeg $tour): TourSegment => $tour->toSegment())->all(), $candidateSegment],
                'start' => $start,
            ];
        });

        $chainConnections = $workdays->flatMap(
            fn (array $workday): array => $this->connectionsAlongChain($workday['driver']->warehouse->coordinate, $workday['segments'])
        );
        $travelTime->preload($chainConnections->all(), $mode->value);

        $driverRows = $workdays->map(function (array $workday) use ($travelTime, $workdayEstimator, $legsBuilder, $mode): array {
            $driver = $workday['driver'];
            $warehouse = $driver->warehouse->coordinate;
            $projected = $workday['start']; // TourStart: the projected tour's selected start/end stops.
            $estimate = $workdayEstimator->total($warehouse, $workday['segments'], $mode->value);
            $legs = $legsBuilder->build($warehouse, $workday['prior_tours']->all(), $projected->start, $projected->end, $mode->value);

            // The two connections bracketing the projected tour (feature 017), read from the
            // cache already preloaded for the projection — no extra routing call.
            $incoming = $this->incomingPoint($driver, $workday['prior_tours']);

            return [
                'id' => $driver->id,
                'name' => $driver->name,
                'image_url' => $driver->image_url,
                'modes' => $driver->deliveryModes->pluck('label')->all(),
                'warehouse_name' => $driver->warehouse->name,
                'projected_seconds' => $estimate->projectedDurationS,
                'projected_incomplete' => $estimate->incomplete,
                'time_to_tour' => $travelTime->durationBetween($incoming, $projected->start, $mode->value),
                'time_from_tour' => $travelTime->durationBetween($projected->end, $warehouse, $mode->value),
                'start_index' => $projected->startIndex,
                'legs' => array_map(fn (WorkdayLeg $leg): array => $leg->toArray(), $legs),
            ];
        });

        return response()->json(['data' => $driverRows->values()]);
    }

    /**
     * The tours already assigned to each driver for the date, as ordered prior-tour
     * legs (recorded start/end, shape, stop coordinates, own duration).
     *
     * @param  array<int, int>  $driverIds
     * @return Collection<int, Collection<int, PriorTourLeg>>
     */
    private function priorToursByDriver(string $date, array $driverIds): Collection
    {
        if ($driverIds === []) {
            return collect();
        }

        $assignments = DB::table('driver_tour')
            ->join('tours', 'tours.id', '=', 'driver_tour.tour_id')
            ->where('driver_tour.date', $date)
            ->whereIn('driver_tour.driver_id', $driverIds)
            ->orderBy('driver_tour.driver_id')
            ->orderBy('driver_tour.sequence')
            ->get([
                'driver_tour.driver_id',
                'driver_tour.tour_id',
                'driver_tour.start_latitude', 'driver_tour.start_longitude',
                'driver_tour.end_latitude', 'driver_tour.end_longitude',
                'tours.loop',
                'tours.travel_duration_s',
            ]);

        $stopsByTour = DB::table('stops')
            ->whereIn('tour_id', $assignments->pluck('tour_id')->unique())
            ->orderBy('position')
            ->get(['tour_id', 'latitude', 'longitude', 'duration_s'])
            ->groupBy('tour_id');

        return $assignments
            ->groupBy('driver_id')
            ->map(fn (Collection $driverAssignments): Collection => $driverAssignments->map(
                fn (object $assignment): PriorTourLeg => $this->priorTourFromAssignment($assignment, $stopsByTour)
            ));
    }

    /**
     * One assignment row as a prior-tour leg; its duration mirrors `Tour::total_duration_s`
     * (null travel time propagates as unknown, never coerced to 0).
     *
     * @param  Collection<int, Collection<int, object>>  $stopsByTour
     */
    private function priorTourFromAssignment(object $assignment, Collection $stopsByTour): PriorTourLeg
    {
        $stops = $stopsByTour->get($assignment->tour_id, collect());

        $tourDurationS = $assignment->travel_duration_s === null
            ? null
            : (int) $assignment->travel_duration_s + (int) $stops->sum('duration_s');

        return new PriorTourLeg(
            start: new Coordinate((float) $assignment->start_latitude, (float) $assignment->start_longitude),
            end: new Coordinate((float) $assignment->end_latitude, (float) $assignment->end_longitude),
            loop: (bool) $assignment->loop,
            stopCoordinates: $stops->map(
                fn (object $stop): Coordinate => new Coordinate((float) $stop->latitude, (float) $stop->longitude)
            )->values()->all(),
            durationS: $tourDurationS,
        );
    }

    /**
     * Where the driver arrives at the candidate tour from: the end of their last
     * prior tour, or their warehouse when the day is empty.
     *
     * @param  Collection<int, PriorTourLeg>  $priorTours
     */
    private function incomingPoint(Driver $driver, Collection $priorTours): Coordinate
    {
        return $priorTours->last()?->end ?? $driver->warehouse->coordinate;
    }

    /**
     * The connections from each driver's incoming point to every valid start of the candidate tour.
     *
     * @param  Collection<int, Driver>  $drivers
     * @param  Collection<int, Collection<int, PriorTourLeg>>  $priorToursByDriver
     * @return array<int, array{0: Coordinate, 1: Coordinate}>
     */
    private function connectionsToStartCandidates(Collection $drivers, Collection $priorToursByDriver, Tour $candidateTour): array
    {
        $startPoints = $candidateTour->startCandidates()->map(fn (Stop $stop): Coordinate => $stop->coordinate);

        return $drivers->flatMap(function (Driver $driver) use ($priorToursByDriver, $startPoints): array {
            $incoming = $this->incomingPoint($driver, $priorToursByDriver->get($driver->id, collect()));

            return $startPoints->map(fn (Coordinate $startPoint): array => [$incoming, $startPoint])->all();
        })->all();
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
