<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Enums\WeekDay as WeekDayEnum;
use App\Http\Requests\AvailableDriversRequest;
use App\Models\Driver;
use App\Models\Tour;
use App\Services\Coordinate;
use App\Services\TourSegment;
use App\Services\TourStartSelector;
use App\Services\TravelTimeService;
use App\Services\WorkdayEstimator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lists the drivers able to run an optimized tour and, for each, their projected
 * working day if given it (feature 013): the drive from their warehouse to the first
 * tour, each tour's duration, the drives between tours, and the drive home. The chain
 * is assembled from the driver's already-assigned tours for the date plus the candidate
 * tour appended last (assignment order).
 *
 * The many road-time lookups are deduplicated and fetched in a capped concurrent batch
 * ({@see TravelTimeService}); the start of the candidate tour is chosen per driver by
 * {@see TourStartSelector} and the day is summed by {@see WorkdayEstimator}.
 */
class DriverController extends Controller
{
    /**
     * GET /api/tour/drivers?mode=<trucking|driving|walking>&date=<YYYY-MM-DD>&tour=<id>
     */
    public function available(
        AvailableDriversRequest $request,
        TravelTimeService $travel,
        TourStartSelector $selector,
        WorkdayEstimator $estimator,
    ): JsonResponse {
        $mode = DeliveryModeEnum::from($request->validated('mode'));
        $date = $request->date('date')->toDateString();
        $day = WeekDayEnum::fromDate($request->date('date'));
        $candidate = Tour::with('stops')->findOrFail($request->integer('tour'));

        $drivers = Driver::available($mode, $day)->get();
        $priorSegments = $this->priorSegmentsByDriver($date, $drivers->pluck('id')->all());

        // Prime the selection legs (each driver's incoming point → each valid start) so
        // the closest-start choice reads a pre-fetched, deduplicated, capped batch.
        $travel->prime($this->selectionLegs($drivers, $priorSegments, $candidate), $mode->value);

        $candidateDurationS = $candidate->total_duration_s;
        $rows = $drivers->map(function (Driver $driver) use ($priorSegments, $candidate, $candidateDurationS, $selector, $mode): array {
            $priors = $priorSegments->get($driver->id, collect())->all();
            $incoming = empty($priors) ? $driver->warehouse->coordinate : end($priors)->end;
            $start = $selector->select($incoming, $candidate, $mode->value);

            return [
                'driver' => $driver,
                'segments' => [...$priors, new TourSegment($start->start, $start->end, $candidateDurationS)],
                'start_index' => $start->startIndex,
            ];
        });

        // Prime every connecting leg of the resolved chains, then total each day.
        $travel->prime($rows->flatMap(fn (array $row): array => $this->chainLegs($row['driver']->warehouse->coordinate, $row['segments']))->all(), $mode->value);

        $data = $rows->map(function (array $row) use ($estimator, $mode): array {
            $driver = $row['driver'];
            $estimate = $estimator->total($driver->warehouse->coordinate, $row['segments'], $mode->value);

            return [
                'id' => $driver->id,
                'name' => $driver->name,
                'image_url' => $driver->image_url,
                'modes' => $driver->deliveryModes->pluck('label')->all(),
                'warehouse_name' => $driver->warehouse->name,
                'projected_seconds' => $estimate->projectedDurationS,
                'projected_incomplete' => $estimate->incomplete,
                'start_index' => $row['start_index'],
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    /**
     * The tours already assigned to each driver for the date, as ordered resolved
     * segments. Two queries (assignments + grouped stop sums) — no N+1. A tour whose
     * own travel time is unknown yields a null segment duration (an unknown that flags
     * the day incomplete rather than silently counting 0).
     *
     * @param  array<int, int>  $driverIds
     * @return Collection<int, Collection<int, TourSegment>> keyed by driver id
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

        $stopSeconds = DB::table('stops')
            ->whereIn('tour_id', $assignments->pluck('tour_id')->unique())
            ->groupBy('tour_id')
            ->selectRaw('tour_id, SUM(duration_s) as seconds')
            ->pluck('seconds', 'tour_id');

        return $assignments
            ->groupBy('driver_id')
            ->map(fn (Collection $rows): Collection => $rows->map(fn (object $row): TourSegment => new TourSegment(
                new Coordinate((float) $row->start_latitude, (float) $row->start_longitude),
                new Coordinate((float) $row->end_latitude, (float) $row->end_longitude),
                $row->travel_duration_s === null
                    ? null
                    : (int) $row->travel_duration_s + (int) ($stopSeconds[$row->tour_id] ?? 0),
            )));
    }

    /**
     * The candidate-start selection legs across all drivers: from each driver's incoming
     * point (last prior tour's end, or their warehouse) to every valid start of the
     * candidate tour.
     *
     * @param  Collection<int, Driver>  $drivers
     * @param  Collection<int, Collection<int, TourSegment>>  $priorSegments
     * @return array<int, array{0: Coordinate, 1: Coordinate}>
     */
    private function selectionLegs(Collection $drivers, Collection $priorSegments, Tour $candidate): array
    {
        $starts = $candidate->startCandidates()->map(fn ($stop): Coordinate => $stop->coordinate);

        return $drivers->flatMap(function (Driver $driver) use ($priorSegments, $starts): array {
            $priors = $priorSegments->get($driver->id, collect());
            $incoming = $priors->isEmpty() ? $driver->warehouse->coordinate : $priors->last()->end;

            return $starts->map(fn (Coordinate $start): array => [$incoming, $start])->all();
        })->all();
    }

    /**
     * Every connecting leg of a warehouse → segments → warehouse chain.
     *
     * @param  array<int, TourSegment>  $segments
     * @return array<int, array{0: Coordinate, 1: Coordinate}>
     */
    private function chainLegs(Coordinate $warehouse, array $segments): array
    {
        $legs = [];
        $previous = $warehouse;
        foreach ($segments as $segment) {
            $legs[] = [$previous, $segment->start];
            $previous = $segment->end;
        }
        $legs[] = [$previous, $warehouse];

        return $legs;
    }
}
