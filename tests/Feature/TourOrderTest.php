<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Feature 025 (US4): the reorder endpoint — conflict 409, block 422, force persist.
 * The optimal-recompute ordering is unit-tested with a mocked router; here we verify
 * the endpoint wiring and persistence, so routing is kept down (deterministic).
 */
class TourOrderTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-07-06';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(fn () => throw new ConnectionException('routing down'));
    }

    private function dayTour(User $user, Driver $driver, int $sequence, float $lat): Tour
    {
        $tour = Tour::factory()->withMode('driving')->create([
            'user_id' => $user->id,
            'loop' => false,
            'travel_duration_s' => 300,
        ]);
        Stop::factory()->for($tour)->create(['latitude' => $lat, 'longitude' => 2.35, 'duration_s' => 100, 'position' => 0]);
        Stop::factory()->for($tour)->create(['latitude' => $lat + 0.01, 'longitude' => 2.36, 'duration_s' => 100, 'position' => 1]);

        $tour->drivers()->attach($driver, [
            'date' => self::MONDAY,
            'start_latitude' => $lat, 'start_longitude' => 2.35,
            'end_latitude' => $lat + 0.01, 'end_longitude' => 2.36,
            'sequence' => $sequence,
        ]);

        return $tour;
    }

    public function test_a_mismatched_tour_set_conflicts(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->create();
        $a = $this->dayTour($user, $driver, 0, 48.85);
        $this->dayTour($user, $driver, 1, 48.90);

        $this->actingAs($user)
            ->postJson(route('api.driver.tour-order', ['driver' => $driver->id]), [
                'date' => self::MONDAY,
                'tour_ids' => [$a->id, 999999], // not the day's set
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'assignment_conflict');
    }

    public function test_an_unroutable_connection_blocks_and_persists_nothing(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->create();
        $a = $this->dayTour($user, $driver, 0, 48.85);
        $b = $this->dayTour($user, $driver, 1, 48.90);

        $this->actingAs($user)
            ->postJson(route('api.driver.tour-order', ['driver' => $driver->id]), [
                'date' => self::MONDAY,
                'tour_ids' => [$b->id, $a->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'unroutable_connection')
            ->assertJsonStructure(['failed_leg' => ['from', 'to']]);

        // The blocked attempt left the original order intact.
        $this->assertSame(0, (int) DB::table('driver_tour')->where('tour_id', $a->id)->value('sequence'));
        $this->assertSame(1, (int) DB::table('driver_tour')->where('tour_id', $b->id)->value('sequence'));
    }

    public function test_force_persists_the_new_order_and_returns_the_day(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->create();
        $a = $this->dayTour($user, $driver, 0, 48.85);
        $b = $this->dayTour($user, $driver, 1, 48.90);

        $this->actingAs($user)
            ->postJson(route('api.driver.tour-order', ['driver' => $driver->id]), [
                'date' => self::MONDAY,
                'tour_ids' => [$b->id, $a->id],
                'force' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.tours.0.id', $b->id)
            ->assertJsonPath('data.tours.1.id', $a->id);

        $this->assertSame(0, (int) DB::table('driver_tour')->where('tour_id', $b->id)->value('sequence'));
        $this->assertSame(1, (int) DB::table('driver_tour')->where('tour_id', $a->id)->value('sequence'));
    }

    public function test_an_unknown_driver_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.driver.tour-order', ['driver' => 999999]), [
                'date' => self::MONDAY,
                'tour_ids' => [1],
            ])
            ->assertNotFound();
    }
}
