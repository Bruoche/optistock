<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DriverAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    // 2026-07-06 is a Monday; 2026-07-04 is a Saturday.
    private const MONDAY = '2026-07-06';

    private const SATURDAY = '2026-07-04';

    /**
     * Every /route leg costs `$seconds`, so the endpoint's projection never hits the
     * network. Called per test (Http::fake merges, so a single call keeps overrides clean).
     */
    private function fakeEveryLeg(int $seconds): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK', 'total_time' => $seconds, 'total_distance' => 1000])]);
    }

    /**
     * A persisted candidate tour owned by the given user (what the presentation phase
     * holds). Two stops so it has valid start candidates.
     */
    private function candidateTour(User $user, bool $loop = true, int $travelS = 300): Tour
    {
        $tour = Tour::factory()->withMode('driving')->create([
            'user_id' => $user->id,
            'loop' => $loop,
            'travel_duration_s' => $travelS,
        ]);
        Stop::factory()->for($tour)->create(['latitude' => 48.85, 'longitude' => 2.35, 'duration_s' => 100, 'position' => 0]);
        Stop::factory()->for($tour)->create(['latitude' => 48.86, 'longitude' => 2.36, 'duration_s' => 200, 'position' => 1]);

        return $tour;
    }

    private function driversRoute(string $mode, string $date, ?int $tour): string
    {
        return route('api.tour.drivers', array_filter([
            'mode' => $mode,
            'date' => $date,
            'tour' => $tour,
        ], fn ($value): bool => $value !== null));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson($this->driversRoute('driving', self::MONDAY, 1))->assertUnauthorized();
    }

    public function test_missing_tour_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, null))
            ->assertStatus(422);
    }

    public function test_foreign_or_unknown_tour_is_not_found(): void
    {
        $user = User::factory()->create();
        $foreign = $this->candidateTour(User::factory()->create());

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $foreign->id))
            ->assertNotFound();

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, 999999))
            ->assertNotFound();
    }

    public function test_it_returns_only_drivers_matching_mode_and_weekday_alphabetically(): void
    {
        $this->fakeEveryLeg(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user);
        Driver::factory()->withModes(['driving', 'walking'])->withDays(['monday'])->create(['name' => 'Bruno']);
        Driver::factory()->withModes(['walking'])->withDays(['monday'])->create(['name' => 'Carla']);   // wrong mode
        Driver::factory()->withModes(['driving'])->withDays(['tuesday'])->create(['name' => 'Dimitri']); // wrong day
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        $response->assertOk();
        $this->assertSame(['Amelie', 'Bruno'], array_column($response->json('data'), 'name'));
    }

    public function test_it_exposes_warehouse_name_and_shape(): void
    {
        $this->fakeEveryLeg(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user);
        $warehouse = Warehouse::factory()->create(['name' => 'North Depot']);
        Driver::factory()->for($warehouse)->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Amelie')
            ->assertJsonPath('data.0.warehouse_name', 'North Depot')
            ->assertJsonPath('data.0.projected_incomplete', false);
        $this->assertIsInt($response->json('data.0.projected_seconds'));
        $this->assertIsInt($response->json('data.0.start_index'));
    }

    public function test_invalid_or_missing_mode_is_rejected(): void
    {
        $user = User::factory()->create();
        $tour = $this->candidateTour($user);

        $this->actingAs($user)->getJson($this->driversRoute('flying', self::MONDAY, $tour->id))->assertStatus(422);
        $this->actingAs($user)->getJson(route('api.tour.drivers', ['date' => self::MONDAY, 'tour' => $tour->id]))->assertStatus(422);
    }

    public function test_invalid_or_missing_date_is_rejected(): void
    {
        $user = User::factory()->create();
        $tour = $this->candidateTour($user);

        $this->actingAs($user)->getJson(route('api.tour.drivers', ['mode' => 'driving', 'tour' => $tour->id]))->assertStatus(422);
        $this->actingAs($user)->getJson($this->driversRoute('driving', 'not-a-date', $tour->id))->assertStatus(422);
    }

    public function test_no_matching_driver_returns_empty_data(): void
    {
        $user = User::factory()->create();
        $tour = $this->candidateTour($user);
        Driver::factory()->withModes(['walking'])->withDays(['monday'])->create();

        $this->actingAs($user)
            ->getJson($this->driversRoute('trucking', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_projected_day_chains_warehouse_legs_and_tour_duration(): void
    {
        $this->fakeEveryLeg(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300); // total = 300 + 100 + 200 = 600
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // Every leg = 60 s. No prior tours → 2 connecting legs (W→start, end→W).
        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        $response->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 720) // 600 + 2×60
            ->assertJsonPath('data.0.projected_incomplete', false);
    }

    public function test_projected_day_includes_prior_assigned_tours(): void
    {
        $this->fakeEveryLeg(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300); // total 600
        $driver = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // A prior tour for the same date: travel 500 + one 100 s stop = 600.
        $prior = Tour::factory()->withMode('driving')->withStops(1)->assignedTo($driver, self::MONDAY)
            ->create(['travel_duration_s' => 500]);
        $prior->stops()->update(['duration_s' => 100]);

        // Chain W→p.start, p.end→c.start, c.end→W = 3 legs × 60 = 180; tours 600 + 600.
        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        $response->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 1380); // 600 + 600 + 3×60
    }

    public function test_a_failed_leg_makes_the_projection_incomplete(): void
    {
        Http::fake(['*' => Http::response('', 500)]); // every leg fails
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300); // total 600
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        // Legs unknown → count 0; only the tour's own duration remains, flagged.
        $response->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 600)
            ->assertJsonPath('data.0.projected_incomplete', true);
    }

    public function test_a_prior_tour_with_unknown_travel_flags_incomplete(): void
    {
        $this->fakeEveryLeg(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300);
        $driver = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // Prior tour whose own road time is unknown (null) → its segment duration is null.
        Tour::factory()->withMode('driving')->withStops(1)->withUnknownTravelDuration()
            ->assignedTo($driver, self::MONDAY)->create();

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.projected_incomplete', true);
    }

    public function test_start_index_is_the_nearest_valid_candidate(): void
    {
        // Per-leg duration by destination latitude: the pos-1 stop (48.86) is far,
        // the pos-0 stop (48.85) is near, so a one-way tour starts at position 0.
        Http::fake(function (Request $request) {
            $far = str_contains($request->url(), 'destination=48.86');

            return Http::response(['status' => 'OK', 'total_time' => $far ? 900 : 30, 'total_distance' => 1000]);
        });

        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: false);
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.start_index', 0);
    }

    public function test_shared_legs_are_not_requested_more_than_once(): void
    {
        $this->fakeEveryLeg(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user);
        $warehouse = Warehouse::factory()->create();
        // Two drivers sharing a warehouse + no prior tours → identical W↔candidate legs.
        Driver::factory()->for($warehouse)->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);
        Driver::factory()->for($warehouse)->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Bruno']);

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk();

        // Distinct legs: W→stop0, W→stop1 (selection) + chosen start→W (return, loop so
        // end = start). Both drivers share the same warehouse, so 3 requests, never 6.
        Http::assertSentCount(3);
    }
}
