<?php

namespace Tests\Feature;

use App\Jobs\OptimizeTourJob;
use App\Models\User;
use App\Services\CoordinateNormalizer;
use App\Services\TourCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TourOptimizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array{0: float, 1: float}>
     */
    private function validCoordinates(): array
    {
        return [
            [49.89988, 2.30028],
            [48.45101, 6.74833],
            [48.78300, 2.33316],
        ];
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->postJson(route('api.tour.optimize'), ['coordinates' => $this->validCoordinates()])
            ->assertUnauthorized();
    }

    public function test_it_requires_at_least_two_coordinates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.optimize'), ['coordinates' => [[49.89988, 2.30028]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('coordinates');
    }

    public function test_it_rejects_out_of_range_coordinates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.optimize'), ['coordinates' => [[200, 2.30028], [48.45101, 6.74833]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('coordinates.0.0');
    }

    public function test_it_rejects_more_than_ten_coordinates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.optimize'), ['coordinates' => array_fill(0, 11, [48.0, 2.0])])
            ->assertStatus(422)
            ->assertJsonValidationErrors('coordinates');
    }

    public function test_each_coordinate_must_be_a_lat_lng_pair(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.optimize'), ['coordinates' => [[48.0], [2.0, 3.0]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('coordinates.0');
    }

    public function test_optimize_requests_are_rate_limited_per_user(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $payload = ['coordinates' => $this->validCoordinates()];

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)
                ->postJson(route('api.tour.optimize'), $payload)
                ->assertStatus(202);
        }

        $this->actingAs($user)
            ->postJson(route('api.tour.optimize'), $payload)
            ->assertStatus(429);
    }

    public function test_cache_miss_queues_job_and_returns_202(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('api.tour.optimize'), ['coordinates' => $this->validCoordinates()]);

        $response->assertStatus(202)
            ->assertJson(['status' => 'pending'])
            ->assertJsonStructure(['status', 'job_uuid']);

        Queue::assertPushed(OptimizeTourJob::class, function (OptimizeTourJob $job) use ($user): bool {
            return $job->userId === $user->id
                && $job->jobUuid !== ''
                && $job->coordinatesHash !== ''
                && count($job->coordinates) === 3
                // No mode in the request → defaults to trucking.
                && $job->mode === 'trucking';
        });
    }

    public function test_it_rejects_an_unsupported_mode(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.optimize'), [
                'coordinates' => $this->validCoordinates(),
                'mode' => 'flying',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('mode');
    }

    public function test_selected_mode_is_passed_to_the_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.tour.optimize'), [
                'coordinates' => $this->validCoordinates(),
                'mode' => 'walking',
            ])
            ->assertStatus(202);

        Queue::assertPushed(OptimizeTourJob::class, fn (OptimizeTourJob $job): bool => $job->mode === 'walking');
    }

    public function test_a_different_mode_does_not_hit_another_modes_cached_tour(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $coordinates = $this->validCoordinates();

        // A tour cached for trucking…
        $normalized = app(CoordinateNormalizer::class)->normalize($coordinates);
        $coordinatesHash = hash('sha256', (string) json_encode($normalized));
        $tour = ['ordered_stops' => [], 'total_distance_m' => 4200, 'total_duration_s' => 360];
        app(TourCache::class)->putTour('trucking', $coordinatesHash, $tour);

        // …must NOT be served to a walking request: it queues a fresh job instead.
        $this->actingAs($user)
            ->postJson(route('api.tour.optimize'), ['coordinates' => $coordinates, 'mode' => 'walking'])
            ->assertStatus(202);

        Queue::assertPushed(OptimizeTourJob::class, fn (OptimizeTourJob $job): bool => $job->mode === 'walking');
    }

    public function test_concurrent_identical_requests_reuse_one_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $payload = ['coordinates' => $this->validCoordinates()];

        $first = $this->actingAs($user)->postJson(route('api.tour.optimize'), $payload);
        $second = $this->actingAs($user)->postJson(route('api.tour.optimize'), $payload);

        $first->assertStatus(202);
        $second->assertStatus(202)
            ->assertJson(['status' => 'pending', 'job_uuid' => $first->json('job_uuid')]);

        // Only the first request dispatched the expensive upstream job.
        Queue::assertPushed(OptimizeTourJob::class, 1);
    }

    public function test_cache_hit_returns_200_with_tour(): void
    {
        $user = User::factory()->create();
        $coordinates = $this->validCoordinates();

        $normalized = app(CoordinateNormalizer::class)->normalize($coordinates);
        $coordinatesHash = hash('sha256', (string) json_encode($normalized));
        $tour = ['ordered_stops' => [], 'total_distance_m' => 4200, 'total_duration_s' => 360];
        app(TourCache::class)->putTour('trucking', $coordinatesHash, $tour);

        $this->actingAs($user)
            ->postJson(route('api.tour.optimize'), ['coordinates' => $coordinates])
            ->assertStatus(200)
            ->assertJson(['status' => 'done', 'data' => $tour]);
    }

    public function test_reordered_coordinates_hit_the_same_cache(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $coordinates = $this->validCoordinates();

        $normalized = app(CoordinateNormalizer::class)->normalize($coordinates);
        $coordinatesHash = hash('sha256', (string) json_encode($normalized));
        $tour = ['ordered_stops' => [], 'total_distance_m' => 4200, 'total_duration_s' => 360];
        app(TourCache::class)->putTour('trucking', $coordinatesHash, $tour);

        // Same stops, reversed input order, must resolve to the same cache entry.
        $this->actingAs($user)
            ->postJson(route('api.tour.optimize'), ['coordinates' => array_reverse($coordinates)])
            ->assertStatus(200)
            ->assertJson(['status' => 'done', 'data' => $tour]);

        Queue::assertNotPushed(OptimizeTourJob::class);
    }

    public function test_status_endpoint_reports_status(): void
    {
        $user = User::factory()->create();
        app(TourCache::class)->markPending('job-xyz');

        $this->actingAs($user)
            ->getJson(route('api.tour.status', ['job_uuid' => 'job-xyz']))
            ->assertStatus(200)
            ->assertJson(['status' => 'pending']);
    }

    public function test_status_endpoint_returns_done_with_data(): void
    {
        $user = User::factory()->create();
        $tour = ['ordered_stops' => [], 'total_distance_m' => 10, 'total_duration_s' => 5];
        app(TourCache::class)->markDone('job-done', $tour);

        $this->actingAs($user)
            ->getJson(route('api.tour.status', ['job_uuid' => 'job-done']))
            ->assertStatus(200)
            ->assertJson(['status' => 'done', 'data' => $tour]);
    }

    public function test_status_endpoint_returns_failed_with_error(): void
    {
        $user = User::factory()->create();
        app(TourCache::class)->markFailed('job-bad', ['code' => 'timeout', 'message' => 'slow']);

        $this->actingAs($user)
            ->getJson(route('api.tour.status', ['job_uuid' => 'job-bad']))
            ->assertStatus(200)
            ->assertJson(['status' => 'failed', 'error' => ['code' => 'timeout']]);
    }

    public function test_status_endpoint_returns_404_for_unknown_job(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('api.tour.status', ['job_uuid' => 'does-not-exist']))
            ->assertStatus(404);
    }
}
