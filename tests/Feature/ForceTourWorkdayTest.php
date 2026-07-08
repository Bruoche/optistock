<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ForceTourWorkdayTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-06';

    /** A tour forced through the endpoint: manual drive 300 s, two 100 s stops. */
    private function forceTour(User $user): int
    {
        $response = $this->actingAs($user)->postJson(route('api.tour.force'), [
            'stops' => [
                ['lat' => 48.85, 'lng' => 2.35, 'duration_s' => 100],
                ['lat' => 48.86, 'lng' => 2.36, 'duration_s' => 100],
            ],
            'travel_duration_s' => 300,
            'mode' => 'driving',
            'loop' => true,
        ])->assertOk();

        return (int) $response->json('data.id');
    }

    public function test_a_forced_tours_manual_duration_feeds_the_driver_workday(): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK', 'total_time' => 60, 'total_distance' => 1000])]);
        $user = User::factory()->create();
        $tourId = $this->forceTour($user);
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // Workday = manual drive 300 + stops 200 + two 60 s bracketing connections = 620.
        $this->actingAs($user)
            ->getJson(route('api.tour.drivers', ['mode' => 'driving', 'date' => self::MONDAY, 'tour' => $tourId]))
            ->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 620)
            ->assertJsonPath('data.0.projected_incomplete', false);
    }

    public function test_a_forced_tour_is_assignable_to_a_driver(): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK', 'total_time' => 60, 'total_distance' => 1000])]);
        $user = User::factory()->create();
        $tourId = $this->forceTour($user);
        $driver = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.assign', ['tour' => $tourId]), [
                'driver_id' => $driver->id,
                'date' => self::MONDAY,
                'start_index' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.tour_id', $tourId)
            ->assertJsonPath('data.driver_id', $driver->id);

        $this->assertDatabaseHas('driver_tour', ['tour_id' => $tourId, 'driver_id' => $driver->id]);
    }
}
