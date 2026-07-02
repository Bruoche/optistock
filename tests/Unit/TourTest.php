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

    public function test_looping_tour_start_candidates_are_all_stops(): void
    {
        $tour = Tour::factory()->withStops(3)->create(['loop' => true]);

        $this->assertSame([0, 1, 2], $tour->startCandidates()->pluck('position')->all());
    }

    public function test_one_way_tour_start_candidates_are_only_the_two_endpoints(): void
    {
        $tour = Tour::factory()->withStops(4)->create(['loop' => false]);

        $this->assertSame([0, 3], $tour->startCandidates()->pluck('position')->all());
    }

    public function test_single_stop_tour_start_candidate_is_that_one_stop(): void
    {
        $tour = Tour::factory()->withStops(1)->create(['loop' => false]);

        $this->assertSame([0], $tour->startCandidates()->pluck('position')->all());
    }

    public function test_end_stop_for_start_is_same_stop_on_a_loop(): void
    {
        $tour = Tour::factory()->withStops(3)->create(['loop' => true]);
        $start = $tour->stops->firstWhere('position', 1);

        $this->assertTrue($tour->endStopForStart($start)->is($start));
    }

    public function test_end_stop_for_start_is_opposite_endpoint_on_a_one_way(): void
    {
        $tour = Tour::factory()->withStops(4)->create(['loop' => false]);
        $first = $tour->stops->firstWhere('position', 0);
        $last = $tour->stops->firstWhere('position', 3);

        $this->assertTrue($tour->endStopForStart($first)->is($last));
        $this->assertTrue($tour->endStopForStart($last)->is($first));
    }
}
