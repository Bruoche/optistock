<?php

namespace Tests\Feature;

use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DriverAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    // 2026-07-06 is a Monday; 2026-07-04 is a Saturday.
    private const MONDAY = '2026-07-06';

    private const SATURDAY = '2026-07-04';

    /**
     * Every routed connection costs `$seconds`, so the endpoint's projection never hits
     * the network. Called per test (Http::fake merges, so a single call keeps overrides clean).
     */
    private function fakeEveryConnection(int $seconds): void
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
        $this->fakeEveryConnection(60);
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
        $this->fakeEveryConnection(60);
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

    public function test_projected_day_chains_warehouse_connections_and_tour_duration(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300); // total = 300 + 100 + 200 = 600
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // Every connection = 60 s. No prior tours → 2 connections (W→start, end→W).
        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        $response->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 720) // 600 + 2×60
            ->assertJsonPath('data.0.projected_incomplete', false);
    }

    public function test_projected_day_includes_prior_assigned_tours(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300); // total 600
        $driver = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // A prior tour for the same date: travel 500 + one 100 s stop = 600.
        $prior = Tour::factory()->withMode('driving')->withStops(1)->assignedTo($driver, self::MONDAY)
            ->create(['travel_duration_s' => 500]);
        $prior->stops()->update(['duration_s' => 100]);

        // Chain W→p.start, p.end→c.start, c.end→W = 3 connections × 60 = 180; tours 600 + 600.
        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        $response->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 1380); // 600 + 600 + 3×60
    }

    public function test_a_failed_connection_makes_the_projection_incomplete(): void
    {
        Http::fake(['*' => Http::response('', 500)]); // every routing call fails
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300); // total 600
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        // Connections unknown → count 0; only the tour's own duration remains, flagged.
        $response->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 600)
            ->assertJsonPath('data.0.projected_incomplete', true);
    }

    public function test_a_prior_tour_with_unknown_travel_flags_incomplete(): void
    {
        $this->fakeEveryConnection(60);
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
        // Per-connection duration by destination latitude: the pos-1 stop (48.86) is far,
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

    public function test_legs_cover_the_chain_with_connection_geometry_and_lazy_tour_legs(): void
    {
        // Every routed connection carries a polyline, so connection legs get geometry.
        Http::fake(['*' => Http::response([
            'status' => 'OK', 'total_time' => 60, 'total_distance' => 1000,
            'polyline' => '_p~iF~ps|U_ulLnnqC',
        ])]);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user);
        $driver = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);
        Tour::factory()->withMode('driving')->withStops(2)->assignedTo($driver, self::MONDAY)
            ->create(['travel_duration_s' => 500, 'loop' => true]);

        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        $response->assertOk();
        $legs = $response->json('data.0.legs');

        // Chain order: W→prior, prior tour, prior→candidate, candidate→W.
        $this->assertSame(['connection', 'tour', 'connection', 'connection'], array_column($legs, 'kind'));
        $this->assertSame([true, false, true, true], array_column($legs, 'dotted'));
        // Only the two connections bracketing the candidate tour are highlighted.
        $this->assertSame([false, false, true, true], array_column($legs, 'highlight'));

        foreach ([0, 2, 3] as $connectionIndex) {
            $this->assertIsArray($legs[$connectionIndex]['geometry']);
            $this->assertNotEmpty($legs[$connectionIndex]['geometry']);
            $this->assertCount(2, $legs[$connectionIndex]['path']);
        }

        // The prior tour leg is traced lazily by the client: straight path, no geometry.
        $this->assertNull($legs[1]['geometry']);
        $this->assertCount(2, $legs[1]['path']);
        $this->assertTrue($legs[1]['loop']);
    }

    public function test_a_prior_loop_tour_leg_path_is_rotated_to_its_recorded_start(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user);
        $driver = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // Prior loop tour with three stops; the assignment records the middle stop as start/end.
        $prior = Tour::factory()->withMode('driving')->create(['travel_duration_s' => 500, 'loop' => true]);
        Stop::factory()->for($prior)->create(['latitude' => 48.70, 'longitude' => 2.10, 'duration_s' => 60, 'position' => 0]);
        Stop::factory()->for($prior)->create(['latitude' => 48.71, 'longitude' => 2.11, 'duration_s' => 60, 'position' => 1]);
        Stop::factory()->for($prior)->create(['latitude' => 48.72, 'longitude' => 2.12, 'duration_s' => 60, 'position' => 2]);
        $prior->drivers()->sync([$driver->id => [
            'date' => self::MONDAY,
            'start_latitude' => 48.71, 'start_longitude' => 2.11,
            'end_latitude' => 48.71, 'end_longitude' => 2.11,
            'sequence' => 1,
        ]]);

        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        $response->assertOk();
        $tourLeg = collect($response->json('data.0.legs'))->firstWhere('kind', 'tour');
        $this->assertSame([[48.71, 2.11], [48.72, 2.12], [48.70, 2.10]], $tourLeg['path']);
    }

    public function test_legs_do_not_change_the_thirteen_payload_or_add_route_calls(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300);
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        // The 013 fields are untouched and the legs ride the same routed connections:
        // W→stop0, W→stop1 (selection) + start→W (return) = 3 requests, as before legs existed.
        $response->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 720)
            ->assertJsonPath('data.0.projected_incomplete', false)
            ->assertJsonPath('data.0.warehouse_name', fn (mixed $name): bool => is_string($name))
            ->assertJsonPath('data.0.start_index', fn (mixed $index): bool => is_int($index));
        Http::assertSentCount(3);
    }

    public function test_it_exposes_the_two_bracketing_road_times(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300); // total = 600
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // No prior tours → road to tour = W→start = 60, road to warehouse = end→W = 60.
        // These are the same two connections summed into projected_seconds (720), unchanged.
        $response = $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id));

        $response->assertOk()
            ->assertJsonPath('data.0.time_to_tour', 60)
            ->assertJsonPath('data.0.time_from_tour', 60)
            ->assertJsonPath('data.0.projected_seconds', 720);
    }

    public function test_an_unroutable_bracketing_connection_is_null(): void
    {
        Http::fake(['*' => Http::response('', 500)]); // every routing call fails
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300);
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.time_to_tour', null)
            ->assertJsonPath('data.0.time_from_tour', null)
            ->assertJsonPath('data.0.projected_incomplete', true);
    }

    public function test_it_exposes_the_warehouse_coordinate(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300);
        $warehouse = Warehouse::factory()->create(['latitude' => 48.5, 'longitude' => 2.5]);
        Driver::factory()->for($warehouse)->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.warehouse_coordinate', [48.5, 2.5]);
    }

    public function test_previous_tour_end_is_null_without_a_prior_tour(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300); // total = 600
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // No prior tour → the driver departs from the warehouse, so no "0" origin.
        // projected_seconds stays 720 (600 + 2×60), unchanged by the added fields.
        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.previous_tour_end', null)
            ->assertJsonPath('data.0.projected_seconds', 720);
    }

    public function test_previous_tour_end_is_the_last_prior_tour_end(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300);
        $driver = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // A prior tour whose recorded end is a known point → that point is the incoming origin.
        $prior = Tour::factory()->withMode('driving')->withStops(1)->create(['travel_duration_s' => 500]);
        $prior->drivers()->sync([$driver->id => [
            'date' => self::MONDAY,
            'start_latitude' => 48.70, 'start_longitude' => 2.10,
            'end_latitude' => 48.71, 'end_longitude' => 2.11,
            'sequence' => 0,
        ]]);

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.previous_tour_end', [48.71, 2.11]);
    }

    public function test_projected_seconds_includes_a_workday_break_over_six_hours(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300);
        $tour->stops()->update(['duration_s' => 11000]); // 2 stops → 22000 s of stops; total 22300
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // Day = 60 + 22300 + 60 = 22420 (> 6 h, ≤ 9 h); driving = 420 (< 4 h 30) → +30 min break.
        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 24220); // 22420 + 1800
    }

    public function test_projected_seconds_includes_a_larger_break_over_nine_hours(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300);
        $tour->stops()->update(['duration_s' => 16500]); // 2 stops → 33000 s; total 33300
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // Day = 60 + 33300 + 60 = 33420 (> 9 h) → +45 min break.
        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 36120); // 33420 + 2700
    }

    public function test_the_driving_rule_alone_can_add_a_break_on_a_short_day(): void
    {
        $this->fakeEveryConnection(8200); // each connection 8200 s
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300); // total 600 (300 travel + 300 stops)
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // Day = 8200 + 600 + 8200 = 17000 (< 6 h → no workday break); driving = 16700 → one 4 h 30
        // block → +45 min from the driving rule alone.
        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.projected_seconds', 19700); // 17000 + 2700
    }

    public function test_added_break_equals_the_whole_break_for_a_driver_with_no_prior_tour(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300);
        $tour->stops()->update(['duration_s' => 11000]); // day 22420 → +30 min
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.added_break', 1800);
    }

    public function test_added_break_is_zero_and_projected_unchanged_below_any_threshold(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300); // total 600, day 720
        Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.added_break', 0)
            ->assertJsonPath('data.0.projected_seconds', 720); // unchanged, no break
    }

    public function test_added_break_is_only_the_increase_the_candidate_causes(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300);
        $tour->stops()->update(['duration_s' => 5000]); // 2 stops → 10000 s; candidate total 10300
        $driver = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        // Prior tour alone: 60 + (300 + 22000) + 60 = 22420 → break 1800 (over 6 h).
        $prior = Tour::factory()->withMode('driving')->withStops(1)->assignedTo($driver, self::MONDAY)
            ->create(['travel_duration_s' => 300]);
        $prior->stops()->update(['duration_s' => 22000]);

        // With the candidate: 60 + 22300 + 60 + 10300 + 60 = 32780 → break 2700 (over 9 h).
        // added_break = 2700 − 1800 = 900.
        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.added_break', 900);
    }

    public function test_added_break_clamps_to_zero_when_the_candidate_is_unroutable(): void
    {
        // The candidate's stops are unreachable while the prior tour's return to the warehouse is a
        // long routable drive — the raw with−without delta is negative, so added_break clamps to 0.
        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'destination=48.85') || str_contains($url, 'destination=48.86')) {
                return Http::response('', 500); // any connection into a candidate stop fails
            }
            if (str_contains($url, 'origin=48.71') && str_contains($url, 'destination=48.5')) {
                return Http::response(['status' => 'OK', 'total_time' => 22000, 'total_distance' => 1000]);
            }

            return Http::response(['status' => 'OK', 'total_time' => 60, 'total_distance' => 1000]);
        });

        $user = User::factory()->create();
        $tour = $this->candidateTour($user, loop: true, travelS: 300); // stops at 48.85 / 48.86
        $warehouse = Warehouse::factory()->create(['latitude' => 48.5, 'longitude' => 2.5]);
        $driver = Driver::factory()->for($warehouse)->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);

        $prior = Tour::factory()->withMode('driving')->withStops(1)->create(['travel_duration_s' => 300]);
        $prior->stops()->update(['duration_s' => 100]);
        $prior->drivers()->sync([$driver->id => [
            'date' => self::MONDAY,
            'start_latitude' => 48.70, 'start_longitude' => 2.10,
            'end_latitude' => 48.71, 'end_longitude' => 2.11,
            'sequence' => 0,
        ]]);

        // Prior-only day crosses the driving/workday threshold (long 22000 s return) → break > 0;
        // the with-candidate day loses that return and its candidate legs fail → break 0. Clamp to 0.
        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk()
            ->assertJsonPath('data.0.added_break', 0);
    }

    public function test_the_database_query_count_does_not_grow_with_drivers_or_prior_tours(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user);
        $driver = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);
        Tour::factory()->withMode('driving')->withStops(1)->assignedTo($driver, self::MONDAY)
            ->create(['travel_duration_s' => 500]);

        $queriesWithOneDriver = $this->countQueriesForDriversRequest($user, $tour->id);

        foreach (['Bruno', 'Carla'] as $name) {
            $extra = Driver::factory()->withModes(['driving'])->withDays(['monday'])->create(['name' => $name]);
            Tour::factory()->withMode('driving')->withStops(2)->assignedTo($extra, self::MONDAY)
                ->create(['travel_duration_s' => 400]);
        }

        $queriesWithThreeDrivers = $this->countQueriesForDriversRequest($user, $tour->id);

        $this->assertSame($queriesWithOneDriver, $queriesWithThreeDrivers);
    }

    private function countQueriesForDriversRequest(User $user, int $tourId): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tourId))
            ->assertOk();

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }

    public function test_shared_connections_are_not_requested_more_than_once(): void
    {
        $this->fakeEveryConnection(60);
        $user = User::factory()->create();
        $tour = $this->candidateTour($user);
        $warehouse = Warehouse::factory()->create();
        // Two drivers sharing a warehouse + no prior tours → identical W↔candidate connections.
        Driver::factory()->for($warehouse)->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Amelie']);
        Driver::factory()->for($warehouse)->withModes(['driving'])->withDays(['monday'])->create(['name' => 'Bruno']);

        $this->actingAs($user)
            ->getJson($this->driversRoute('driving', self::MONDAY, $tour->id))
            ->assertOk();

        // Distinct connections: W→stop0, W→stop1 (selection) + chosen start→W (return, loop so
        // end = start). Both drivers share the same warehouse, so 3 requests, never 6.
        Http::assertSentCount(3);
    }
}
