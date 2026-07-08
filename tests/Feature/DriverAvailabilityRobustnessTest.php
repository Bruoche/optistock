<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use App\Services\OpenStreetRouteClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature 024: the driver-assignment back-end must never block or 500 when the routing
 * API is unavailable — it degrades to a flagged best-effort estimate instead.
 */
class DriverAvailabilityRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-06';

    private function candidateTour(User $user): Tour
    {
        $tour = Tour::factory()->withMode('driving')->create([
            'user_id' => $user->id,
            'loop' => true,
            'travel_duration_s' => 300,
        ]);
        Stop::factory()->for($tour)->create(['latitude' => 48.85, 'longitude' => 2.35, 'duration_s' => 100, 'position' => 0]);
        Stop::factory()->for($tour)->create(['latitude' => 48.86, 'longitude' => 2.36, 'duration_s' => 200, 'position' => 1]);

        return $tour;
    }

    public function test_an_unreachable_routing_host_still_returns_a_flagged_best_effort_day(): void
    {
        // Every /route call fails to connect, as with a dead host.
        Http::fake(function (): void {
            throw new ConnectionException('Connection refused');
        });

        $user = User::factory()->create();
        $tour = $this->candidateTour($user);
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // No hang, no 500: connections unknown → count 0, only the tour's own duration remains, flagged.
        $this->actingAs($user)
            ->getJson(route('api.tour.drivers', ['mode' => 'driving', 'date' => self::MONDAY, 'tour' => $tour->id]))
            ->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 600) // 300 drive + 300 stops
            ->assertJsonPath('data.0.projected_incomplete', true)
            ->assertJsonPath('data.0.time_to_tour', null)
            ->assertJsonPath('data.0.time_from_tour', null);
    }

    public function test_the_route_client_is_wired_with_the_configured_connect_timeout(): void
    {
        config()->set('services.openstreet.route_connect_timeout', 7);
        $this->app->forgetInstance(OpenStreetRouteClient::class);

        $client = $this->app->make(OpenStreetRouteClient::class);

        $this->assertSame(7, $client->connectTimeout());
    }
}
