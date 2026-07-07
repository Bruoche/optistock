<?php

namespace App\DTOs;

use App\Models\Stop;
use App\Models\Tour;

/**
 * The tour data that hydrates the optimize page when editing an existing tour
 * (feature 020): its id, mode, shape, and stops with delivery durations in minutes,
 * in visiting order. No date — an unassigned tour has none.
 */
final class EditTourData
{
    /**
     * @param  array<int, array{lat: float, lng: float, duration_minutes: int}>  $stops
     */
    private function __construct(
        public readonly int $id,
        public readonly string $mode,
        public readonly bool $loop,
        public readonly array $stops,
    ) {}

    public static function fromTour(Tour $tour): self
    {
        $tour->loadMissing('deliveryMode', 'stops');

        $stops = $tour->stops->map(fn (Stop $stop): array => [
            'lat' => $stop->latitude,
            'lng' => $stop->longitude,
            'duration_minutes' => intdiv($stop->duration_s, 60),
        ])->all();

        return new self($tour->id, $tour->deliveryMode->label, $tour->loop, $stops);
    }

    /**
     * @return array{id: int, mode: string, loop: bool, stops: array<int, array{lat: float, lng: float, duration_minutes: int}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'mode' => $this->mode,
            'loop' => $this->loop,
            'stops' => $this->stops,
        ];
    }
}
