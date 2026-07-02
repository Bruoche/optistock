<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Enums\WeekDay as WeekDayEnum;
use App\Http\Requests\AvailableDriversRequest;
use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Services\Coordinate;
use App\Services\TourSegment;
use App\Services\TourStartSelector;
use App\Services\TravelTimeService;
use App\Services\WorkdayEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Lists the drivers available for a tour with each one's projected working day if given it. */
class DriverController extends Controller
{
    /** GET /api/tour/drivers — available drivers plus their chained projected day and chosen start. */
    public function available(
        AvailableDriversRequest $request,
        TravelTimeService $travelTime,
        TourStartSelector $startSelector,
        WorkdayEstimator $workdayEstimator,
    ): JsonResponse {
        $mode = DeliveryModeEnum::from($request->validated('mode'));
        $date = $request->date('date')->toDateString();
        $weekday = WeekDayEnum::fromDate($request->date('date'));
        $candidateTour = Tour::with('stops')->findOrFail($request->integer('tour'));

        $drivers = Driver::available($mode, $weekday)->get();
        $priorSegmentsByDriver = $this->priorSegmentsByDriver($date, $drivers->pluck('id')->all());

        $travelTime->preload($this->connectionsToStartCandidates($drivers, $priorSegmentsByDriver, $candidateTour), $mode->value);

        $workdays = $drivers->map(function (Driver $driver) use ($priorSegmentsByDriver, $candidateTour, $startSelector, $mode): array {
            $priorSegments = $priorSegmentsByDriver->get($driver->id, collect());
            $incoming = $this->incomingPoint($driver, $priorSegments);
            $start = $startSelector->select($incoming, $candidateTour, $mode->value);
            $candidateSegment = new TourSegment($start->start, $start->end, $candidateTour->total_duration_s);

            return [
                'driver' => $driver,
                'segments' => [...$priorSegments->all(), $candidateSegment],
                'start_index' => $start->startIndex,
            ];
        });

        $chainConnections = $workdays->flatMap(
            fn (array $workday): array => $this->connectionsAlongChain($workday['driver']->warehouse->coordinate, $workday['segments'])
        );
        $travelTime->preload($chainConnections->all(), $mode->value);

        $driverRows = $workdays->map(function (array $workday) use ($workdayEstimator, $mode): array {
            $driver = $workday['driver'];
            $estimate = $workdayEstimator->total($driver->warehouse->coordinate, $workday['segments'], $mode->value);

            return [
                'id' => $driver->id,
                'name' => $driver->name,
                'image_url' => $driver->image_url,
                'modes' => $driver->deliveryModes->pluck('label')->all(),
                'warehouse_name' => $driver->warehouse->name,
                'projected_seconds' => $estimate->projectedDurationS,
                'projected_incomplete' => $estimate->incomplete,
                'start_index' => $workday['start_index'],
            ];
        });

        return response()->json(['data' => $driverRows->values()]);
    }

    /**
     * The tours already assigned to each driver for the date, as ordered resolved segments.
     *
     * @param  array<int, int>  $driverIds
     * @return Collection<int, Collection<int, TourSegment>>
     */
    private function priorSegmentsByDriver(string $date, array $driverIds): Collection
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
                'tours.travel_duration_s',
            ]);

        $stopSecondsByTour = DB::table('stops')
            ->whereIn('tour_id', $assignments->pluck('tour_id')->unique())
            ->groupBy('tour_id')
            ->selectRaw('tour_id, SUM(duration_s) as seconds')
            ->pluck('seconds', 'tour_id');

        return $assignments
            ->groupBy('driver_id')
            ->map(fn (Collection $driverAssignments): Collection => $driverAssignments->map(
                fn (object $assignment): TourSegment => $this->segmentFromAssignment($assignment, $stopSecondsByTour)
            ));
    }

    /**
     * One assignment row reduced to a segment; its duration mirrors `Tour::total_duration_s`
     * (null travel time propagates as unknown, never coerced to 0).
     *
     * @param  Collection<int, mixed>  $stopSecondsByTour
     */
    private function segmentFromAssignment(object $assignment, Collection $stopSecondsByTour): TourSegment
    {
        $tourDurationS = $assignment->travel_duration_s === null
            ? null
            : (int) $assignment->travel_duration_s + (int) ($stopSecondsByTour[$assignment->tour_id] ?? 0);

        return new TourSegment(
            new Coordinate((float) $assignment->start_latitude, (float) $assignment->start_longitude),
            new Coordinate((float) $assignment->end_latitude, (float) $assignment->end_longitude),
            $tourDurationS,
        );
    }

    /**
     * Where the driver arrives at the candidate tour from: the end of their last
     * prior tour, or their warehouse when the day is empty.
     *
     * @param  Collection<int, TourSegment>  $priorSegments
     */
    private function incomingPoint(Driver $driver, Collection $priorSegments): Coordinate
    {
        return $priorSegments->last()?->end ?? $driver->warehouse->coordinate;
    }

    /**
     * The connections from each driver's incoming point to every valid start of the candidate tour.
     *
     * @param  Collection<int, Driver>  $drivers
     * @param  Collection<int, Collection<int, TourSegment>>  $priorSegmentsByDriver
     * @return array<int, array{0: Coordinate, 1: Coordinate}>
     */
    private function connectionsToStartCandidates(Collection $drivers, Collection $priorSegmentsByDriver, Tour $candidateTour): array
    {
        $startPoints = $candidateTour->startCandidates()->map(fn (Stop $stop): Coordinate => $stop->coordinate);

        return $drivers->flatMap(function (Driver $driver) use ($priorSegmentsByDriver, $startPoints): array {
            $incoming = $this->incomingPoint($driver, $priorSegmentsByDriver->get($driver->id, collect()));

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
