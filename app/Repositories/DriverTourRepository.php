<?php

namespace App\Repositories;

use App\Models\Tour;
use App\Services\Coordinate;
use App\Services\PriorTourLeg;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Owns the `driver_tour` pivot data access: reading each driver's already-assigned
 * tours for a date (as prior-tour legs) and recording a new assignment.
 */
class DriverTourRepository
{
    /**
     * The tours already assigned to each driver for the date, as ordered prior-tour
     * legs (recorded start/end, shape, stop coordinates, own duration).
     *
     * @param  array<int, int>  $driverIds
     * @return Collection<int, Collection<int, PriorTourLeg>>
     */
    public function priorToursByDriver(string $date, array $driverIds): Collection
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

    /** One past the driver's latest assigned tour for the date (0 when they have none). */
    public function nextSequence(int $driverId, string $date): int
    {
        $current = DB::table('driver_tour')
            ->where('driver_id', $driverId)
            ->where('date', $date)
            ->max('sequence');

        return $current === null ? 0 : (int) $current + 1;
    }

    /**
     * Record the tour → driver assignment (one driver per tour: the pivot's unique
     * `tour_id` means `sync` replaces any prior driver). A concurrent double-assign that
     * races the unique constraint collides with the same row, so it is treated as success.
     *
     * @param  array<string, mixed>  $pivot
     */
    public function assign(Tour $tour, int $driverId, array $pivot): void
    {
        try {
            DB::transaction(fn () => $tour->drivers()->sync([$driverId => $pivot]));
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }
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
            stopSecondsS: (int) $stops->sum('duration_s'),
        );
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000' || $e->getCode() === '23505';
    }
}
