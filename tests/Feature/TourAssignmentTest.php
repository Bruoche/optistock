<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function drivingTour(): Tour
    {
        return Tour::factory()->for($this->user)->withMode('driving')->create();
    }

    private function eligibleDriver(): Driver
    {
        return Driver::factory()->withModes(['driving'])->withDays(['monday'])->create();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $tour = $this->drivingTour();

        $this->postJson(route('api.tour.assign', $tour), ['driver_id' => 1, 'date' => self::MONDAY])
            ->assertUnauthorized();
    }

    public function test_it_assigns_an_eligible_driver(): void
    {
        $tour = $this->drivingTour();
        $driver = $this->eligibleDriver();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY])
            ->assertOk()
            ->assertJson(['data' => ['tour_id' => $tour->id, 'driver_id' => $driver->id, 'date' => self::MONDAY]]);

        $this->assertDatabaseHas('driver_tour', [
            'tour_id' => $tour->id,
            'driver_id' => $driver->id,
            'date' => self::MONDAY,
        ]);
    }

    public function test_a_driver_with_the_wrong_mode_is_rejected(): void
    {
        $tour = $this->drivingTour();
        $driver = Driver::factory()->withModes(['walking'])->withDays(['monday'])->create();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY])
            ->assertStatus(422)
            ->assertJsonValidationErrors('driver_id');

        $this->assertDatabaseCount('driver_tour', 0);
    }

    public function test_a_driver_not_scheduled_on_the_weekday_is_rejected(): void
    {
        $tour = $this->drivingTour();
        $driver = Driver::factory()->withModes(['driving'])->withDays(['tuesday'])->create();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY])
            ->assertStatus(422)
            ->assertJsonValidationErrors('driver_id');

        $this->assertDatabaseCount('driver_tour', 0);
    }

    public function test_unknown_tour_returns_404(): void
    {
        $driver = $this->eligibleDriver();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', ['tour' => 999999]), ['driver_id' => $driver->id, 'date' => self::MONDAY])
            ->assertNotFound();
    }

    public function test_another_users_tour_returns_404_and_records_nothing(): void
    {
        $tour = Tour::factory()->for(User::factory()->create())->withMode('driving')->create();
        $driver = $this->eligibleDriver();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $driver->id, 'date' => self::MONDAY])
            ->assertNotFound();

        $this->assertDatabaseCount('driver_tour', 0);
    }

    public function test_a_second_assign_is_idempotent_and_re_targets_the_same_tour(): void
    {
        $tour = $this->drivingTour();
        $first = $this->eligibleDriver();
        $second = $this->eligibleDriver();

        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $first->id, 'date' => self::MONDAY])
            ->assertOk();
        $this->actingAs($this->user)
            ->postJson(route('api.tour.assign', $tour), ['driver_id' => $second->id, 'date' => self::MONDAY])
            ->assertOk();

        // One driver per tour: the row was updated, not duplicated.
        $this->assertDatabaseCount('driver_tour', 1);
        $this->assertDatabaseHas('driver_tour', ['tour_id' => $tour->id, 'driver_id' => $second->id]);
    }
}
