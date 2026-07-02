<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TourAssignmentTest extends TestCase
{
    use RefreshDatabase;

    // 2026-07-06 is a Monday.
    private const MONDAY = '2026-07-06';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function loopTour(): Tour
    {
        $tour = Tour::factory()->for($this->user)->withMode('driving')->create(['loop' => true]);
        Stop::factory()->for($tour)->create(['latitude' => 48.85, 'longitude' => 2.35, 'duration_s' => 100, 'position' => 0]);
        Stop::factory()->for($tour)->create(['latitude' => 48.86, 'longitude' => 2.36, 'duration_s' => 200, 'position' => 1]);

        return $tour;
    }

    private function oneWayTour(): Tour
    {
        $tour = Tour::factory()->for($this->user)->withMode('driving')->create(['loop' => false]);
        Stop::factory()->for($tour)->create(['latitude' => 48.80, 'longitude' => 2.30, 'duration_s' => 100, 'position' => 0]);
        Stop::factory()->for($tour)->create(['latitude' => 48.85, 'longitude' => 2.35, 'duration_s' => 150, 'position' => 1]);
        Stop::factory()->for($tour)->create(['latitude' => 48.90, 'longitude' => 2.40, 'duration_s' => 200, 'position' => 2]);

        return $tour;
    }

    private function eligibleDriver(): Driver
    {
        return Driver::factory()->withModes(['driving'])->withDays(['monday'])->create();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $tour = $this->loopTour();

        $this->postJson(route('api.tour.assign', $tour), ['driver_id' => 1, 'date' => self::MONDAY, 'start_index' => 0])
            ->assertUnauthorized();
    }

    public function test_it_assigns_an_eligible_driver(): void
    {
        $tour = $this->loopTour();
        $driver = $this->eligibleDriver();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY, 'start_index' => 0])
            ->assertOk()
            ->assertJson(['data' => ['tour_id' => $tour->id, 'driver_id' => $driver->id, 'date' => self::MONDAY, 'start_index' => 0, 'sequence' => 0]]);

        $this->assertDatabaseHas('driver_tour', [
            'tour_id' => $tour->id,
            'driver_id' => $driver->id,
            'date' => self::MONDAY,
        ]);
    }

    public function test_missing_start_index_is_rejected(): void
    {
        $tour = $this->loopTour();
        $driver = $this->eligibleDriver();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_index');
    }

    public function test_interior_start_index_on_a_one_way_tour_is_rejected(): void
    {
        $tour = $this->oneWayTour(); // valid starts: positions 0 and 2
        $driver = $this->eligibleDriver();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY, 'start_index' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_index');

        $this->assertDatabaseCount('driver_tour', 0);
    }

    public function test_a_loop_assignment_records_the_same_start_and_end_stop(): void
    {
        $tour = $this->loopTour();
        $driver = $this->eligibleDriver();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY, 'start_index' => 1])
            ->assertOk();

        $pivot = DB::table('driver_tour')->first();
        // Loop: end = start (position 1 → 48.86, 2.36).
        $this->assertSame(48.86, (float) $pivot->start_latitude);
        $this->assertSame(48.86, (float) $pivot->end_latitude);
        $this->assertSame(2.36, (float) $pivot->end_longitude);
    }

    public function test_a_one_way_assignment_records_opposite_endpoints(): void
    {
        $tour = $this->oneWayTour();
        $driver = $this->eligibleDriver();

        // Start at position 2 (48.90) → end is the opposite endpoint, position 0 (48.80).
        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY, 'start_index' => 2])
            ->assertOk();

        $pivot = DB::table('driver_tour')->first();
        $this->assertSame(48.90, (float) $pivot->start_latitude);
        $this->assertSame(48.80, (float) $pivot->end_latitude);
    }

    public function test_sequence_increments_per_driver_and_date(): void
    {
        $driver = $this->eligibleDriver();
        $first = $this->loopTour();
        $second = $this->loopTour();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $first), ['driver_id' => $driver->id, 'date' => self::MONDAY, 'start_index' => 0])
            ->assertJsonPath('data.sequence', 0);
        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $second), ['driver_id' => $driver->id, 'date' => self::MONDAY, 'start_index' => 0])
            ->assertJsonPath('data.sequence', 1);
    }

    public function test_a_driver_with_the_wrong_mode_is_rejected(): void
    {
        $tour = $this->loopTour();
        $driver = Driver::factory()->withModes(['walking'])->withDays(['monday'])->create();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY, 'start_index' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors('driver_id');

        $this->assertDatabaseCount('driver_tour', 0);
    }

    public function test_unknown_tour_returns_404(): void
    {
        $driver = $this->eligibleDriver();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', ['tour' => 999999]), ['driver_id' => $driver->id, 'date' => self::MONDAY, 'start_index' => 0])
            ->assertNotFound();
    }

    public function test_another_users_tour_returns_404_and_records_nothing(): void
    {
        $tour = Tour::factory()->for(User::factory()->create())->withMode('driving')->withStops(2)->create();
        $driver = $this->eligibleDriver();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY, 'start_index' => 0])
            ->assertNotFound();

        $this->assertDatabaseCount('driver_tour', 0);
    }

    public function test_a_second_assign_is_idempotent_and_re_targets_the_same_tour(): void
    {
        $tour = $this->loopTour();
        $first = $this->eligibleDriver();
        $second = $this->eligibleDriver();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $first->id, 'date' => self::MONDAY, 'start_index' => 0])
            ->assertOk();
        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $second->id, 'date' => self::MONDAY, 'start_index' => 0])
            ->assertOk();

        // One driver per tour: the row was updated, not duplicated.
        $this->assertDatabaseCount('driver_tour', 1);
        $this->assertDatabaseHas('driver_tour', ['tour_id' => $tour->id, 'driver_id' => $second->id]);
    }

    public function test_assign_consumes_the_given_start_index_without_reselecting(): void
    {
        $tour = $this->oneWayTour();
        $driver = $this->eligibleDriver();

        // The endpoint records exactly the provided index's stop (no selection re-run):
        // start_index 0 → start 48.80, end the opposite endpoint 48.90.
        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY, 'start_index' => 0])
            ->assertOk()
            ->assertJsonPath('data.start_index', 0);

        $pivot = DB::table('driver_tour')->first();
        $this->assertSame(48.80, (float) $pivot->start_latitude);
        $this->assertSame(48.90, (float) $pivot->end_latitude);
    }
}
