<?php

namespace Tests\Feature;

use App\Events\TourOptimizationFailed;
use App\Events\TourOptimized;
use App\Jobs\OptimizeTourJob;
use App\Models\Tour;
use App\Models\User;
use App\Services\CoordinateNormalizer;
use App\Services\OpenStreetTspClient;
use App\Services\TourCache;
use App\Services\TourRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TourPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @return array<int, array{lat: float, lng: float}>
     */
    private function coordinates(): array
    {
        return [
            ['lat' => 49.89988, 'lng' => 2.30028],
            ['lat' => 48.45101, 'lng' => 6.74833],
            ['lat' => 48.78300, 'lng' => 2.33316],
        ];
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $coordinates
     * @param  array<int, int>  $durations  parallel to $coordinates
     * @return array<string, list<int>>
     */
    private function durationByCoord(array $coordinates, array $durations): array
    {
        $map = [];
        foreach ($coordinates as $index => $coordinate) {
            $map[TourRecorder::coordinateKey($coordinate['lat'], $coordinate['lng'])][] = $durations[$index];
        }

        return $map;
    }

    public function test_job_path_persists_one_tour_with_stops_mapped_through_the_tsp_reorder(): void
    {
        Event::fake();
        // TSP visits input indices in the order 2, 0, 1 — durations must follow the coord, not the slot.
        Http::fake(['*' => Http::response([
            'OPTIMIZATION' => [2, 0, 1],
            'STEPS_DISTANCES' => ['TOTAL' => 4200],
            'STEPS_DURATIONS' => ['TOTAL' => 360],
        ])]);

        $coordinates = $this->coordinates();
        $durations = [100, 200, 300]; // for coords 0, 1, 2 respectively

        (new OptimizeTourJob(
            'job-persist',
            $this->user->id,
            'hash-p',
            $coordinates,
            $this->durationByCoord($coordinates, $durations),
            'trucking',
            true,
        ))->handle(app(OpenStreetTspClient::class), app(TourCache::class), app(TourRecorder::class));

        $this->assertDatabaseCount('tours', 1);
        $tour = Tour::with('stops')->first();
        $this->assertSame(360, $tour->travel_duration_s);
        $this->assertSame($this->user->id, $tour->user_id);

        // position 0 = coord index 2 (dur 300), position 1 = coord 0 (dur 100), position 2 = coord 1 (dur 200).
        $this->assertSame([300, 100, 200], $tour->stops->pluck('duration_s')->all());
        $this->assertSame([0, 1, 2], $tour->stops->pluck('position')->all());
    }

    public function test_cache_hit_persists_one_tour(): void
    {
        $coordinates = $this->coordinates();
        $normalized = app(CoordinateNormalizer::class)->normalize(
            array_map(fn (array $c): array => [$c['lat'], $c['lng']], $coordinates),
        );
        $hash = hash('sha256', (string) json_encode($normalized));
        app(TourCache::class)->putTour('trucking', true, $hash, [
            'ordered_stops' => [
                ['lat' => 48.78300, 'lng' => 2.33316, 'order' => 0],
                ['lat' => 49.89988, 'lng' => 2.30028, 'order' => 1],
                ['lat' => 48.45101, 'lng' => 6.74833, 'order' => 2],
            ],
            'total_distance_m' => 4200,
            'total_duration_s' => 360,
        ]);

        $stops = [
            ['lat' => 49.89988, 'lng' => 2.30028, 'duration_s' => 100],
            ['lat' => 48.45101, 'lng' => 6.74833, 'duration_s' => 200],
            ['lat' => 48.78300, 'lng' => 2.33316, 'duration_s' => 300],
        ];

        $this->actingAs($this->user)
            ->postJson(route('api.tour.optimize'), ['stops' => $stops])
            ->assertOk()
            ->assertJsonPath('status', 'done');

        $this->assertDatabaseCount('tours', 1);
        $tour = Tour::with('stops')->first();
        // ordered_stops order: coord 48.783 (dur 300), 49.899 (dur 100), 48.451 (dur 200).
        $this->assertSame([300, 100, 200], $tour->stops->pluck('duration_s')->all());
    }

    public function test_failed_optimization_persists_nothing(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $coordinates = $this->coordinates();
        (new OptimizeTourJob(
            'job-fail',
            $this->user->id,
            'hash-f',
            $coordinates,
            $this->durationByCoord($coordinates, [1, 2, 3]),
            'trucking',
            true,
        ))->handle(app(OpenStreetTspClient::class), app(TourCache::class), app(TourRecorder::class));

        $this->assertDatabaseCount('tours', 0);
    }

    public function test_job_persist_failure_surfaces_persist_failed_and_keeps_cache(): void
    {
        Event::fake([TourOptimized::class, TourOptimizationFailed::class]);
        Http::fake(['*' => Http::response([
            'OPTIMIZATION' => [0, 1, 2],
            'STEPS_DISTANCES' => ['TOTAL' => 4200],
            'STEPS_DURATIONS' => ['TOTAL' => 360],
        ])]);

        $coordinates = $this->coordinates();
        // Empty duration map → no duration for any stop → record throws.
        (new OptimizeTourJob(
            'job-pf',
            $this->user->id,
            'hash-pf',
            $coordinates,
            [],
            'trucking',
            true,
        ))->handle(app(OpenStreetTspClient::class), app(TourCache::class), app(TourRecorder::class));

        $this->assertDatabaseCount('tours', 0);
        Event::assertDispatched(TourOptimizationFailed::class, fn (TourOptimizationFailed $e): bool => $e->error['code'] === 'persist_failed');
        Event::assertNotDispatched(TourOptimized::class);
        // The expensive TSP result stays cached for a retry.
        $this->assertNotNull(app(TourCache::class)->getTour('trucking', true, 'hash-pf'));
    }

    public function test_cache_hit_persist_failure_returns_persist_failed(): void
    {
        $coordinates = $this->coordinates();
        $normalized = app(CoordinateNormalizer::class)->normalize(
            array_map(fn (array $c): array => [$c['lat'], $c['lng']], $coordinates),
        );
        $hash = hash('sha256', (string) json_encode($normalized));
        // Cached ordered_stops carry a coordinate that is NOT among the request stops → unmappable.
        app(TourCache::class)->putTour('trucking', true, $hash, [
            'ordered_stops' => [['lat' => 1.0, 'lng' => 1.0, 'order' => 0]],
            'total_distance_m' => 10,
            'total_duration_s' => 5,
        ]);

        $stops = array_map(fn (array $c): array => $c + ['duration_s' => 100], $coordinates);

        $this->actingAs($this->user)
            ->postJson(route('api.tour.optimize'), ['stops' => $stops])
            ->assertOk()
            ->assertJson(['status' => 'failed', 'error' => ['code' => 'persist_failed']]);

        $this->assertDatabaseCount('tours', 0);
    }
}
