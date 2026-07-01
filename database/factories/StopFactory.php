<?php

namespace Database\Factories;

use App\Models\Stop;
use App\Models\Tour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stop>
 */
class StopFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tour_id' => Tour::factory(),
            'latitude' => fake()->latitude(48, 49),
            'longitude' => fake()->longitude(2, 3),
            'duration_s' => fake()->numberBetween(60, 1800),
            'position' => 0,
        ];
    }
}
