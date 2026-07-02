<?php

namespace Database\Factories;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Models\DeliveryMode;
use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tour>
 */
class TourFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'delivery_mode_id' => fn (): int => DeliveryMode::firstOrCreate(
                ['label' => fake()->randomElement(DeliveryModeEnum::cases())->value],
            )->id,
            'loop' => true,
            'travel_duration_s' => fake()->numberBetween(300, 3600),
            'total_distance_m' => fake()->numberBetween(1000, 50000),
        ];
    }

    /**
     * Pin the tour to a specific delivery mode label (deterministic for tests).
     */
    public function withMode(string $label): static
    {
        return $this->state(fn (): array => [
            'delivery_mode_id' => DeliveryMode::firstOrCreate(['label' => $label])->id,
        ]);
    }

    /**
     * A road travel duration that could not be determined (FR-012).
     */
    public function withUnknownTravelDuration(): static
    {
        return $this->state(fn (): array => [
            'travel_duration_s' => null,
            'total_distance_m' => null,
        ]);
    }

    /**
     * Attach `$count` ordered stops (positions 0..count-1).
     */
    public function withStops(int $count = 3): static
    {
        return $this->afterCreating(function (Tour $tour) use ($count): void {
            for ($position = 0; $position < $count; $position++) {
                Stop::factory()->for($tour)->create(['position' => $position]);
            }
        });
    }

    /**
     * Assign this tour to a driver for a date (writes the `driver_tour` pivot). The
     * start/end coordinates default to the tour's first/last stop; `sequence` orders the
     * driver's day (feature 013). Chain `withStops()` first so the stops exist.
     */
    public function assignedTo(Driver $driver, string $date, int $sequence = 0): static
    {
        return $this->afterCreating(function (Tour $tour) use ($driver, $date, $sequence): void {
            $stops = $tour->stops;
            $start = $stops->first();
            $end = $stops->last();

            $tour->drivers()->attach($driver, [
                'date' => $date,
                'start_latitude' => $start?->latitude ?? 0,
                'start_longitude' => $start?->longitude ?? 0,
                'end_latitude' => $end?->latitude ?? 0,
                'end_longitude' => $end?->longitude ?? 0,
                'sequence' => $sequence,
            ]);
        });
    }
}
