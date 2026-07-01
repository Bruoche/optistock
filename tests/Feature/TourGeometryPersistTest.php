<?php

namespace Tests\Feature;

use App\Models\Tour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TourGeometryPersistTest extends TestCase
{
    use RefreshDatabase;

    private function okLeg(int $distance = 1000, int $duration = 120): array
    {
        return ['polyline' => '_p~iF~ps|U', 'total_distance' => $distance, 'total_time' => $duration, 'status' => 0];
    }

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    private function stops(): array
    {
        return [
            [49.89988, 2.30028],
            [48.45101, 6.74833],
            [48.78300, 2.33316],
        ];
    }

    public function test_geometry_with_tour_id_updates_travel_totals(): void
    {
        Http::fake(['*' => Http::response($this->okLeg(1000, 120))]);
        $user = User::factory()->create();
        $tour = Tour::factory()->for($user)->create(['travel_duration_s' => 999, 'total_distance_m' => 1]);

        $this->actingAs($user)
            ->postJson(route('api.tour.geometry'), ['tour_id' => $tour->id, 'stops' => $this->stops()])
            ->assertOk();

        // Closed 3-stop tour → 3 legs → 3000 m / 360 s road totals persisted.
        $tour->refresh();
        $this->assertSame(360, $tour->travel_duration_s);
        $this->assertSame(3000, $tour->total_distance_m);
    }

    public function test_two_point_tour_is_traced_and_persisted(): void
    {
        Http::fake(['*' => Http::response($this->okLeg(500, 60))]);
        $user = User::factory()->create();
        $tour = Tour::factory()->for($user)->withUnknownTravelDuration()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.geometry'), [
                'tour_id' => $tour->id,
                'stops' => [[49.89988, 2.30028], [48.78300, 2.33316]],
            ])
            ->assertOk();

        // Closed 2-stop tour → 2 legs → 1000 m / 120 s; the null seed is replaced.
        $tour->refresh();
        $this->assertSame(120, $tour->travel_duration_s);
        $this->assertSame(1000, $tour->total_distance_m);
    }

    public function test_foreign_tour_id_persists_nothing_but_still_traces(): void
    {
        Http::fake(['*' => Http::response($this->okLeg(1000, 120))]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $tour = Tour::factory()->for($owner)->create(['travel_duration_s' => 999, 'total_distance_m' => 1]);

        $this->actingAs($other)
            ->postJson(route('api.tour.geometry'), ['tour_id' => $tour->id, 'stops' => $this->stops()])
            ->assertOk()
            ->assertJsonPath('total_duration_s', 360);

        // Not owned → seed untouched.
        $tour->refresh();
        $this->assertSame(999, $tour->travel_duration_s);
    }

    public function test_failed_trace_leaves_the_seed_untouched(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        $user = User::factory()->create();
        $tour = Tour::factory()->for($user)->create(['travel_duration_s' => 999, 'total_distance_m' => 1]);

        $this->actingAs($user)
            ->postJson(route('api.tour.geometry'), ['tour_id' => $tour->id, 'stops' => $this->stops()])
            ->assertOk()
            ->assertJsonPath('total_duration_s', null);

        $tour->refresh();
        $this->assertSame(999, $tour->travel_duration_s);
    }
}
