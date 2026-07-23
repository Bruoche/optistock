<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Feature 025 (US1): the driver-day endpoint returns the ordered day, totals, and neutral legs. */
class DriverDayApiTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-06';

    private function dayTour(User $user, Driver $driver, string $mode, ?int $travel, array $stopDurations, int $sequence): Tour
    {
        $tour = Tour::factory()->withMode($mode)->create([
            'user_id' => $user->id,
            'loop' => true,
            'travel_duration_s' => $travel,
        ]);

        foreach ($stopDurations as $i => $duration) {
            Stop::factory()->for($tour)->create([
                'latitude' => 48.85 + $i * 0.01,
                'longitude' => 2.35 + $i * 0.01,
                'duration_s' => $duration,
                'position' => $i,
            ]);
        }

        $stops = $tour->stops;
        $tour->drivers()->attach($driver, [
            'date' => self::MONDAY,
            'start_latitude' => $stops->first()->latitude,
            'start_longitude' => $stops->first()->longitude,
            'end_latitude' => $stops->last()->latitude,
            'end_longitude' => $stops->last()->longitude,
            'sequence' => $sequence,
        ]);

        return $tour;
    }

    public function test_it_returns_the_day_ordered_with_totals_and_legs(): void
    {
        // Routing down → connections unknown; the tours' own durations still resolve.
        Http::fake(fn () => throw new ConnectionException('no routing'));

        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->create(['name' => 'Amelie']);
        $tourA = $this->dayTour($user, $driver, 'driving', 300, [100, 100], 0);
        $tourB = $this->dayTour($user, $driver, 'driving', 600, [150, 150], 1);

        $response = $this->actingAs($user)
            ->getJson(route('api.driver.day', ['driver' => $driver->id, 'date' => self::MONDAY]))
            ->assertOk();

        $response
            ->assertJsonPath('data.driver.name', 'Amelie')
            ->assertJsonPath('data.mode', 'driving')
            ->assertJsonPath('data.tours.0.id', $tourA->id)
            ->assertJsonPath('data.tours.0.sequence', 0)
            ->assertJsonPath('data.tours.1.id', $tourB->id)
            ->assertJsonPath('data.tours.0.total_duration_s', 500)   // 300 travel + 200 stops
            ->assertJsonPath('data.tours.0.driven_duration_s', 300)
            ->assertJsonPath('data.tours.0.stop_seconds', 200)
            ->assertJsonPath('data.tours.0.stops.0.index', 1)
            ->assertJsonPath('data.tours.0.stops.1.index', 2)
            // Day totals: connections unknown → incomplete lower bound; stops exact; driven = tour travel.
            ->assertJsonPath('data.workday.stop_seconds', 500)
            ->assertJsonPath('data.workday.driven_seconds', 900)
            ->assertJsonPath('data.workday.total_seconds', 1400)
            ->assertJsonPath('data.workday.incomplete', true);

        // Legs: connection, tour, connection, tour, connection — all neutral (no highlight).
        $legs = $response->json('data.legs');
        $this->assertSame(
            ['connection', 'tour', 'connection', 'tour', 'connection'],
            array_column($legs, 'kind'),
        );
        $this->assertNotContains(true, array_column($legs, 'highlight'));
    }

    public function test_an_empty_day_returns_zero_totals_and_no_tours(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->create();

        $this->actingAs($user)
            ->getJson(route('api.driver.day', ['driver' => $driver->id, 'date' => self::MONDAY]))
            ->assertOk()
            ->assertJsonPath('data.tours', [])
            ->assertJsonPath('data.legs', [])
            ->assertJsonPath('data.mode', null)
            ->assertJsonPath('data.workday.total_seconds', 0)
            ->assertJsonPath('data.workday.stop_seconds', 0)
            ->assertJsonPath('data.workday.incomplete', false);
    }

    public function test_an_unknown_driver_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('api.driver.day', ['driver' => 999999, 'date' => self::MONDAY]))
            ->assertNotFound();
    }
}
