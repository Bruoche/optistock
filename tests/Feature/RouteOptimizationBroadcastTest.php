<?php

namespace Tests\Feature;

use App\Events\RouteOptimizationFailed;
use App\Events\RouteOptimized;
use App\Jobs\OptimizeRouteJob;
use App\Services\OpenStreetTspClient;
use App\Services\RouteCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class RouteOptimizationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array{lat: float, lng: float}>
     */
    private function coordinates(): array
    {
        return [
            ['lat' => 49.89988, 'lng' => 2.30028],
            ['lat' => 48.78300, 'lng' => 2.33316],
        ];
    }

    private function makeJob(string $uuid = 'job-1', int $userId = 42): OptimizeRouteJob
    {
        return new OptimizeRouteJob($uuid, $userId, 'hash-1', $this->coordinates());
    }

    public function test_successful_job_broadcasts_route_optimized(): void
    {
        Event::fake([RouteOptimized::class, RouteOptimizationFailed::class]);
        Http::fake([
            '*' => Http::response([
                'DIMENSION' => 2,
                'TOUR' => 'closed',
                'OPTIMIZATION' => [0, 1],
                'STEPS_DISTANCES' => ['TOTAL' => 1000],
                'STEPS_DURATIONS' => ['TOTAL' => 120],
            ]),
        ]);

        $job = $this->makeJob();
        $job->handle(app(OpenStreetTspClient::class), app(RouteCache::class));

        Event::assertDispatched(RouteOptimized::class, function (RouteOptimized $event): bool {
            return $event->jobUuid === 'job-1'
                && $event->userId === 42
                && $event->data['total_distance_m'] === 1000;
        });
        Event::assertNotDispatched(RouteOptimizationFailed::class);

        $status = app(RouteCache::class)->getStatus('job-1');
        $this->assertSame('done', $status['status']);
        $this->assertSame(['lat' => 49.89988, 'lng' => 2.30028, 'order' => 0], $status['data']['ordered_stops'][0]);
    }

    public function test_successful_job_caches_result_for_reuse(): void
    {
        Event::fake();
        Http::fake([
            '*' => Http::response(['OPTIMIZATION' => [], 'STEPS_DISTANCES' => ['TOTAL' => 1000], 'STEPS_DURATIONS' => ['TOTAL' => 120]]),
        ]);

        $this->makeJob()->handle(app(OpenStreetTspClient::class), app(RouteCache::class));

        $cached = app(RouteCache::class)->getResult(42, 'hash-1');
        $this->assertSame(1000, $cached['total_distance_m']);
    }

    public function test_api_failure_broadcasts_failure_event(): void
    {
        Event::fake([RouteOptimized::class, RouteOptimizationFailed::class]);
        Http::fake(['*' => Http::response('', 500)]);

        $this->makeJob()->handle(app(OpenStreetTspClient::class), app(RouteCache::class));

        Event::assertDispatched(RouteOptimizationFailed::class, function (RouteOptimizationFailed $event): bool {
            return $event->jobUuid === 'job-1' && $event->error['code'] === 'api_error';
        });
        Event::assertNotDispatched(RouteOptimized::class);

        $this->assertSame('failed', app(RouteCache::class)->getStatus('job-1')['status']);
    }

    public function test_invalid_response_broadcasts_failure_event(): void
    {
        Event::fake([RouteOptimized::class, RouteOptimizationFailed::class]);
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'No route'])]);

        $this->makeJob()->handle(app(OpenStreetTspClient::class), app(RouteCache::class));

        Event::assertDispatched(RouteOptimizationFailed::class, function (RouteOptimizationFailed $event): bool {
            return $event->error['code'] === 'invalid_response';
        });
    }

    public function test_failed_callback_broadcasts_failure_event(): void
    {
        Event::fake([RouteOptimizationFailed::class]);

        $this->makeJob()->failed(new RuntimeException('worker crashed'));

        Event::assertDispatched(RouteOptimizationFailed::class, function (RouteOptimizationFailed $event): bool {
            return $event->jobUuid === 'job-1' && $event->error['code'] === 'job_failed';
        });

        $this->assertSame('failed', app(RouteCache::class)->getStatus('job-1')['status']);
    }
}
