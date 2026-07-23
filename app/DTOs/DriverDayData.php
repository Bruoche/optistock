<?php

namespace App\DTOs;

use App\Models\Driver;
use App\Services\DayAssignment;
use App\Services\WorkdayLeg;
use Illuminate\Support\Collection;

/**
 * The whole driver-day payload (feature 025): identity, the day's single mode, workday
 * totals, the ordered tours (stops in driven order), and the neutral drawable legs — the
 * one response the driver-management page loads for a date (contracts/driver-day.md).
 */
final class DriverDayData
{
    /**
     * @param  Collection<int, DayAssignment>  $assignments
     * @param  array{mode: ?string, workday: array<string, mixed>}  $summary
     * @param  array<int, WorkdayLeg>  $legs
     */
    public function __construct(
        private readonly Driver $driver,
        private readonly string $date,
        private readonly Collection $assignments,
        private readonly array $summary,
        private readonly array $legs,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $warehouse = $this->driver->warehouse;

        return [
            'driver' => [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
                'image_url' => $this->driver->image_url,
                'modes' => $this->driver->deliveryModes->pluck('label')->all(),
                'warehouse_id' => $warehouse->id,
                'warehouse_name' => $warehouse->name,
                'warehouse_coordinate' => [$warehouse->latitude, $warehouse->longitude],
            ],
            'date' => $this->date,
            'mode' => $this->summary['mode'],
            'workday' => $this->summary['workday'],
            'tours' => $this->assignments->map(fn (DayAssignment $assignment): array => [
                'id' => $assignment->tourId,
                'sequence' => $assignment->sequence,
                'loop' => $assignment->loop,
                'total_duration_s' => $assignment->totalDurationS(),
                'driven_duration_s' => $assignment->drivenDurationS,
                'stop_seconds' => $assignment->stopSecondsS,
                'start_coordinate' => [$assignment->start->lat, $assignment->start->lng],
                'stops' => array_map(fn (array $stop): array => [
                    'index' => $stop['index'],
                    'lat' => $stop['coordinate']->lat,
                    'lng' => $stop['coordinate']->lng,
                    'duration_s' => $stop['duration_s'],
                ], $assignment->stops),
            ])->all(),
            'legs' => array_map(fn (WorkdayLeg $leg): array => $leg->toArray(), $this->legs),
        ];
    }
}
