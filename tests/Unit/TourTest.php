<?php

namespace Tests\Unit;

use App\Models\Stop;
use App\Models\Tour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TourTest extends TestCase
{
    use RefreshDatabase;

    public function test_total_duration_is_travel_plus_stop_durations(): void
    {
        $tour = Tour::factory()->create(['travel_duration_s' => 600]);
        Stop::factory()->for($tour)->create(['duration_s' => 300, 'position' => 0]);
        Stop::factory()->for($tour)->create(['duration_s' => 120, 'position' => 1]);

        $this->assertSame(1020, $tour->fresh()->total_duration_s);
    }

    public function test_total_duration_is_null_when_travel_duration_is_unknown(): void
    {
        $tour = Tour::factory()->withUnknownTravelDuration()->create();
        Stop::factory()->for($tour)->create(['duration_s' => 300, 'position' => 0]);

        // Null travel → null total, never the stops-only sum (FR-012).
        $this->assertNull($tour->fresh()->total_duration_s);
    }

    public function test_stops_are_ordered_by_position(): void
    {
        $tour = Tour::factory()->create();
        Stop::factory()->for($tour)->create(['position' => 2, 'duration_s' => 1]);
        Stop::factory()->for($tour)->create(['position' => 0, 'duration_s' => 1]);
        Stop::factory()->for($tour)->create(['position' => 1, 'duration_s' => 1]);

        $this->assertSame([0, 1, 2], $tour->stops->pluck('position')->all());
    }
}
