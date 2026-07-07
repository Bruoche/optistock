<?php

namespace App\Services;

use App\Models\Tour;
use App\Repositories\DriverTourRepository;

/**
 * Records the assignment of a persisted tour to a driver: it resolves the driver's
 * entry/exit stops from the chosen start, orders the assignment within the driver's
 * day, and delegates the write to {@see DriverTourRepository}.
 */
class TourAssignmentService
{
    public function __construct(private readonly DriverTourRepository $driverTours) {}

    /**
     * @return array{tour_id: int, driver_id: int, date: string, start_index: int, sequence: int}
     */
    public function assign(Tour $tour, int $driverId, string $date, int $startIndex): array
    {
        $startStop = $tour->stops->firstWhere('position', $startIndex);
        $endStop = $tour->endStopForStart($startStop);
        $sequence = $this->driverTours->nextSequence($driverId, $date);

        $this->driverTours->assign($tour, $driverId, [
            'date' => $date,
            'start_latitude' => $startStop->latitude,
            'start_longitude' => $startStop->longitude,
            'end_latitude' => $endStop->latitude,
            'end_longitude' => $endStop->longitude,
            'sequence' => $sequence,
        ]);

        return [
            'tour_id' => $tour->id,
            'driver_id' => $driverId,
            'date' => $date,
            'start_index' => $startIndex,
            'sequence' => $sequence,
        ];
    }
}
