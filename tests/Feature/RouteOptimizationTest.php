<?php

namespace Tests\Feature;

use App\Jobs\OptimizeRouteJob;
use App\Models\User;
use App\Services\RouteCache;
use App\Services\RouteNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RouteOptimizationTest extends TestCase
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
        $this->postJson(route('api.route.optimize'), ['coordinates' => $this->validCoordinates()])
            ->assertUnauthorized();
    }

    public function test_it_requires_at_least_two_coordinates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.route.optimize'), ['coordinates' => [[49.89988, 2.30028]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('coordinates');
    }

    public function test_it_rejects_out_of_range_coordinates(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('api.route.optimize'), ['coordinates' => [[200, 2.30028], [48.45101, 6.74833]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('coordinates.0.0');
    }

    public function test_cache_miss_queues_job_and_returns_202(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('api.route.optimize'), ['coordinates' => $this->validCoordinates()]);

        $response->assertStatus(202)
            ->assertJson(['status' => 'pending'])
            ->assertJsonStructure(['status', 'job_uuid']);

        Queue::assertPushed(OptimizeRouteJob::class, function (OptimizeRouteJob $job) use ($user): bool {
            return $job->userId === $user->id && $job->jobUuid !== '';
        });
    }

    public function test_concurrent_identical_requests_reuse_one_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $payload = ['coordinates' => $this->validCoordinates()];

        $first = $this->actingAs($user)->postJson(route('api.route.optimize'), $payload);
        $second = $this->actingAs($user)->postJson(route('api.route.optimize'), $payload);

        $first->assertStatus(202);
        $second->assertStatus(202)
            ->assertJson(['status' => 'pending', 'job_uuid' => $first->json('job_uuid')]);

        // Only the first request dispatched the expensive upstream job.
        Queue::assertPushed(OptimizeRouteJob::class, 1);
    }

    public function test_cache_hit_returns_200_with_route(): void
    {
        $user = User::factory()->create();
        $coordinates = $this->validCoordinates();

        $normalized = app(RouteNormalizer::class)->normalize($coordinates);
        $route = ['ordered_stops' => [], 'total_distance_m' => 4200, 'total_duration_s' => 360];
        app(RouteCache::class)->putResult($user->id, $normalized['hash'], $route);

        $this->actingAs($user)
            ->postJson(route('api.route.optimize'), ['coordinates' => $coordinates])
            ->assertStatus(200)
            ->assertJson(['status' => 'done', 'data' => $route]);
    }

    public function test_result_endpoint_reports_status(): void
    {
        $user = User::factory()->create();
        app(RouteCache::class)->markPending('job-xyz');

        $this->actingAs($user)
            ->getJson(route('api.route.result', ['job_uuid' => 'job-xyz']))
            ->assertStatus(200)
            ->assertJson(['status' => 'pending']);
    }

    public function test_result_endpoint_returns_404_for_unknown_job(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('api.route.result', ['job_uuid' => 'does-not-exist']))
            ->assertStatus(404);
    }
}
