<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DriverAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    // 2026-07-06 is a Monday; 2026-07-04 is a Saturday.
    private const MONDAY = '2026-07-06';

    private const SATURDAY = '2026-07-04';

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson(route('api.tour.drivers', ['mode' => 'driving', 'date' => self::MONDAY]))
            ->assertUnauthorized();
    }

    public function test_it_returns_only_drivers_matching_mode_and_weekday_alphabetically(): void
    {
        $user = User::factory()->create();
        Driver::factory()->withModes(['driving', 'walking'])->withDays(['monday'])->create(['name' => 'Bruno']);
        Driver::factory()->withModes(['walking'])->withDays(['monday'])->create(['name' => 'Carla']);   // wrong mode
        Driver::factory()->withModes(['driving'])->withDays(['tuesday'])->create(['name' => 'Dimitri']); // wrong day
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $response = $this->actingAs($user)
            ->getJson(route('api.tour.drivers', ['mode' => 'driving', 'date' => self::MONDAY]));

        $response->assertOk();
        $this->assertSame(['Amelie', 'Bruno'], array_column($response->json('data'), 'name'));
    }

    public function test_weekend_date_returns_only_weekend_scheduled_drivers(): void
    {
        $user = User::factory()->create();
        Driver::factory()->withModes(['driving'])->withDays(['saturday', 'sunday'])->create(['name' => 'Weekend Wendy']);
        Driver::factory()->withModes(['driving'])->withDays(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'])->create(['name' => 'Weekday Walt']);

        $response = $this->actingAs($user)
            ->getJson(route('api.tour.drivers', ['mode' => 'driving', 'date' => self::SATURDAY]));

        $response->assertOk();
        $this->assertSame(['Weekend Wendy'], array_column($response->json('data'), 'name'));
    }

    public function test_it_exposes_image_url_and_modes_shape(): void
    {
        $user = User::factory()->create();
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie', 'image_path' => 'drivers/a.jpg']);
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Bruno', 'image_path' => null]);

        $response = $this->actingAs($user)
            ->getJson(route('api.tour.drivers', ['mode' => 'driving', 'date' => self::MONDAY]));

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Amelie')
            ->assertJsonPath('data.0.modes', ['driving'])
            ->assertJsonPath('data.1.image_url', null);
        $this->assertStringContainsString('drivers/a.jpg', $response->json('data.0.image_url'));
    }

    public function test_invalid_or_missing_mode_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('api.tour.drivers', ['mode' => 'flying', 'date' => self::MONDAY]))->assertStatus(422);
        $this->actingAs($user)->getJson(route('api.tour.drivers', ['date' => self::MONDAY]))->assertStatus(422);
    }

    public function test_invalid_or_missing_date_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson(route('api.tour.drivers', ['mode' => 'driving']))->assertStatus(422);
        $this->actingAs($user)->getJson(route('api.tour.drivers', ['mode' => 'driving', 'date' => 'not-a-date']))->assertStatus(422);
    }

    public function test_no_matching_driver_returns_empty_data(): void
    {
        $user = User::factory()->create();
        Driver::factory()->withModes(['walking'])->withDays(['monday'])->create();

        $this->actingAs($user)
            ->getJson(route('api.tour.drivers', ['mode' => 'trucking', 'date' => self::MONDAY]))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_driver_with_empty_schedule_is_never_listed(): void
    {
        $user = User::factory()->create();
        Driver::factory()->withModes(['driving'])->withDays([])->create(['name' => 'No Schedule']);

        $this->actingAs($user)
            ->getJson(route('api.tour.drivers', ['mode' => 'driving', 'date' => self::MONDAY]))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_assigned_seconds_sums_that_dates_committed_tours(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // Two tours assigned for the queried date: travel 600 + stops (300+120) = 1020, and travel 300 + stop 180 = 480.
        $tourA = Tour::factory()->withMode('driving')->create(['travel_duration_s' => 600]);
        Stop::factory()->for($tourA)->create(['duration_s' => 300, 'position' => 0]);
        Stop::factory()->for($tourA)->create(['duration_s' => 120, 'position' => 1]);
        $tourA->drivers()->attach($driver, ['date' => self::MONDAY]);

        $tourB = Tour::factory()->withMode('driving')->create(['travel_duration_s' => 300]);
        Stop::factory()->for($tourB)->create(['duration_s' => 180, 'position' => 0]);
        $tourB->drivers()->attach($driver, ['date' => self::MONDAY]);

        // A tour on a different date must not count.
        $tourOther = Tour::factory()->withMode('driving')->create(['travel_duration_s' => 9999]);
        $tourOther->drivers()->attach($driver, ['date' => '2026-07-13']);

        $response = $this->actingAs($user)
            ->getJson(route('api.tour.drivers', ['mode' => 'driving', 'date' => self::MONDAY]));

        $response->assertOk()
            ->assertJsonPath('data.0.assigned_seconds', 1500);
    }

    public function test_assigned_seconds_is_zero_with_no_assignments(): void
    {
        $user = User::factory()->create();
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $this->actingAs($user)
            ->getJson(route('api.tour.drivers', ['mode' => 'driving', 'date' => self::MONDAY]))
            ->assertOk()
            ->assertJsonPath('data.0.assigned_seconds', 0);
    }

    public function test_assigned_seconds_counts_unknown_travel_as_zero(): void
    {
        $user = User::factory()->create();
        $driver = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // Unknown travel (null) → only the stop time counts; the sum stays numeric.
        $tour = Tour::factory()->withMode('driving')->withUnknownTravelDuration()->create();
        Stop::factory()->for($tour)->create(['duration_s' => 240, 'position' => 0]);
        $tour->drivers()->attach($driver, ['date' => self::MONDAY]);

        $this->actingAs($user)
            ->getJson(route('api.tour.drivers', ['mode' => 'driving', 'date' => self::MONDAY]))
            ->assertOk()
            ->assertJsonPath('data.0.assigned_seconds', 240);
    }
}
