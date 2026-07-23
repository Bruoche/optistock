<?php

namespace App\Repositories;

use App\Models\Driver;
use App\Models\Tour;
use App\Services\Coordinate;
use App\Services\DayAssignment;
use App\Services\DrivenTourStops;
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

    /**
     * The driver's tours for a date as ordered day assignments (feature 025): running
     * position, mode, recorded entry/exit, and stops in driven order. Ordered by sequence.
     *
     * @return Collection<int, DayAssignment>
     */
    public function assignmentsForDay(Driver $driver, string $date): Collection
    {
        $rows = DB::table('driver_tour')
            ->join('tours', 'tours.id', '=', 'driver_tour.tour_id')
            ->join('delivery_modes', 'delivery_modes.id', '=', 'tours.delivery_mode_id')
            ->where('driver_tour.driver_id', $driver->id)
            ->where('driver_tour.date', $date)
            ->orderBy('driver_tour.sequence')
            ->get([
                'driver_tour.tour_id',
                'driver_tour.sequence',
                'driver_tour.start_latitude', 'driver_tour.start_longitude',
                'driver_tour.end_latitude', 'driver_tour.end_longitude',
                'tours.loop',
                'tours.travel_duration_s',
                'delivery_modes.label as mode',
            ]);

        $stopsByTour = DB::table('stops')
            ->whereIn('tour_id', $rows->pluck('tour_id')->unique())
            ->orderBy('position')
            ->get(['tour_id', 'latitude', 'longitude', 'duration_s'])
            ->groupBy('tour_id');

        return $rows->map(fn (object $row): DayAssignment => $this->dayAssignmentFromRow($row, $stopsByTour))->values();
    }

    /** The tour ids assigned to the driver on a date (feature 025 conflict check). */
    public function assignedTourIds(Driver $driver, string $date): array
    {
        return DB::table('driver_tour')
            ->where('driver_id', $driver->id)
            ->where('date', $date)
            ->pluck('tour_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Persist a recomputed running order for the driver's day (feature 025): update each
     * assignment's sequence and entry/exit points in one transaction.
     *
     * @param  array<int, array{tour_id: int, sequence: int, start_latitude: float, start_longitude: float, end_latitude: float, end_longitude: float}>  $rows
     */
    public function reorder(Driver $driver, string $date, array $rows): void
    {
        DB::transaction(function () use ($driver, $date, $rows): void {
            foreach ($rows as $row) {
                DB::table('driver_tour')
                    ->where('driver_id', $driver->id)
                    ->where('date', $date)
                    ->where('tour_id', $row['tour_id'])
                    ->update([
                        'sequence' => $row['sequence'],
                        'start_latitude' => $row['start_latitude'],
                        'start_longitude' => $row['start_longitude'],
                        'end_latitude' => $row['end_latitude'],
                        'end_longitude' => $row['end_longitude'],
                        'updated_at' => now(),
                    ]);
            }
        });
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

    /**
     * @param  Collection<int, Collection<int, object>>  $stopsByTour
     */
    private function dayAssignmentFromRow(object $row, Collection $stopsByTour): DayAssignment
    {
        $start = new Coordinate((float) $row->start_latitude, (float) $row->start_longitude);
        $end = new Coordinate((float) $row->end_latitude, (float) $row->end_longitude);

        $stopRows = $stopsByTour->get($row->tour_id, collect())->all();
        $drivenStops = DrivenTourStops::order($stopRows, $start, (bool) $row->loop);

        $stops = [];
        foreach ($drivenStops as $position => $stop) {
            $stops[] = [
                'index' => $position + 1,
                'coordinate' => new Coordinate((float) $stop->latitude, (float) $stop->longitude),
                'duration_s' => (int) $stop->duration_s,
            ];
        }

        return new DayAssignment(
            tourId: (int) $row->tour_id,
            sequence: (int) $row->sequence,
            loop: (bool) $row->loop,
            mode: (string) $row->mode,
            start: $start,
            end: $end,
            stops: $stops,
            drivenDurationS: $row->travel_duration_s === null ? null : (int) $row->travel_duration_s,
            stopSecondsS: array_sum(array_column($stops, 'duration_s')),
        );
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000' || $e->getCode() === '23505';
    }
}
