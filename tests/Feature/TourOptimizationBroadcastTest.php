<?php

namespace Tests\Feature;

use App\Events\TourOptimizationFailed;
use App\Events\TourOptimized;
use App\Jobs\OptimizeTourJob;
use App\Models\User;
use App\Services\OpenStreetTspClient;
use App\Services\TourCache;
use App\Services\TourRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TourOptimizationBroadcastTest extends TestCase
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
            ['lat' => 48.78300, 'lng' => 2.33316],
            ['lat' => 43.29650, 'lng' => 5.36980],
        ];
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>  $coordinates
     * @return array<string, list<int>>
     */
    private function durationByCoord(array $coordinates): array
    {
        $map = [];
        foreach ($coordinates as $coordinate) {
            $map[TourRecorder::coordinateKey($coordinate['lat'], $coordinate['lng'])][] = 600;
        }

        return $map;
    }

    /**
     * @param  array<int, array{lat: float, lng: float}>|null  $coordinates
     */
    private function makeJob(string $uuid = 'job-1', ?array $coordinates = null): OptimizeTourJob
    {
        $coordinates ??= $this->coordinates();

        return new OptimizeTourJob(
            $uuid,
            $this->user->id,
            'hash-1',
            $coordinates,
            $this->durationByCoord($coordinates),
            'trucking',
            true,
        );
    }

    private function runJob(OptimizeTourJob $job): void
    {
        $job->handle(app(OpenStreetTspClient::class), app(TourCache::class), app(TourRecorder::class));
    }

    public function test_successful_job_broadcasts_tour_optimized(): void
    {
        Event::fake([TourOptimized::class, TourOptimizationFailed::class]);
        Http::fake([
            '*' => Http::response([
                'DIMENSION' => 2,
                'TOUR' => 'closed',
                'OPTIMIZATION' => [0, 1],
                'STEPS_DISTANCES' => ['TOTAL' => 1000],
                'STEPS_DURATIONS' => ['TOTAL' => 120],
            ]),
        ]);

        $this->runJob($this->makeJob());

        Event::assertDispatched(TourOptimized::class, function (TourOptimized $event): bool {
            return $event->jobUuid === 'job-1'
                && $event->userId === $this->user->id
                && $event->data['total_distance_m'] === 1000
                && is_int($event->data['id']);
        });
        Event::assertNotDispatched(TourOptimizationFailed::class);

        $status = app(TourCache::class)->getJobStatus('job-1');
        $this->assertSame('done', $status['status']);
        $this->assertSame(['lat' => 49.89988, 'lng' => 2.30028, 'order' => 0], $status['data']['ordered_stops'][0]);
    }

    public function test_open_tour_sends_tour_open_to_the_api(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response([
            'OPTIMIZATION' => [0, 1, 2],
            'STEPS_DISTANCES' => ['TOTAL' => 1],
            'STEPS_DURATIONS' => ['TOTAL' => 1],
        ])]);

        // loop=false → the job maps it to tour=open and the client forwards it (004).
        $coordinates = $this->coordinates();
        $this->runJob(new OptimizeTourJob(
            'job-open',
            $this->user->id,
            'hash-open',
            $coordinates,
            $this->durationByCoord($coordinates),
            'trucking',
            false,
        ));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'tour=open'));
    }

    public function test_closed_tour_sends_tour_closed_to_the_api(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response([
            'OPTIMIZATION' => [0, 1, 2],
            'STEPS_DISTANCES' => ['TOTAL' => 1],
            'STEPS_DURATIONS' => ['TOTAL' => 1],
        ])]);

        $this->runJob($this->makeJob());

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'tour=closed'));
    }

    public function test_two_point_job_short_circuits_with_null_metrics_and_no_api_call(): void
    {
        Event::fake([TourOptimized::class, TourOptimizationFailed::class]);
        Http::fake();

        $twoPoints = [
            ['lat' => 49.89988, 'lng' => 2.30028],
            ['lat' => 48.78300, 'lng' => 2.33316],
        ];
        $this->runJob(new OptimizeTourJob(
            'job-2pt',
            $this->user->id,
            'hash-2pt',
            $twoPoints,
            $this->durationByCoord($twoPoints),
            'trucking',
            true,
        ));

        Http::assertNothingSent();
        Event::assertDispatched(TourOptimized::class, function (TourOptimized $event): bool {
            return $event->jobUuid === 'job-2pt'
                && $event->data['total_distance_m'] === null
                && $event->data['total_duration_s'] === null
                && count($event->data['ordered_stops']) === 2;
        });

        $status = app(TourCache::class)->getJobStatus('job-2pt');
        $this->assertSame('done', $status['status']);
        $this->assertNull($status['data']['total_duration_s']);
    }

    public function test_successful_job_caches_tour_for_reuse(): void
    {
        Event::fake();
        Http::fake([
            '*' => Http::response(['OPTIMIZATION' => [], 'STEPS_DISTANCES' => ['TOTAL' => 1000], 'STEPS_DURATIONS' => ['TOTAL' => 120]]),
        ]);

        $this->runJob($this->makeJob());

        $cachedTour = app(TourCache::class)->getTour('trucking', true, 'hash-1');
        $this->assertSame(1000, $cachedTour['total_distance_m']);
        // The cached tour is the pure result — the per-user persisted id is NOT baked in.
        $this->assertArrayNotHasKey('id', $cachedTour);
    }

    public function test_api_failure_broadcasts_failure_event(): void
    {
        Event::fake([TourOptimized::class, TourOptimizationFailed::class]);
        Http::fake(['*' => Http::response('', 500)]);

        $this->runJob($this->makeJob());

        Event::assertDispatched(TourOptimizationFailed::class, function (TourOptimizationFailed $event): bool {
            return $event->jobUuid === 'job-1' && $event->error['code'] === 'api_error';
        });
        Event::assertNotDispatched(TourOptimized::class);

        $this->assertSame('failed', app(TourCache::class)->getJobStatus('job-1')['status']);
    }

    public function test_invalid_response_broadcasts_failure_event(): void
    {
        Event::fake([TourOptimized::class, TourOptimizationFailed::class]);
        Http::fake(['*' => Http::response(['status' => 'error', 'message' => 'No tour'])]);

        $this->runJob($this->makeJob());

        Event::assertDispatched(TourOptimizationFailed::class, function (TourOptimizationFailed $event): bool {
            return $event->error['code'] === 'invalid_response';
        });
    }

    public function test_failed_callback_broadcasts_failure_event(): void
    {
        Event::fake([TourOptimizationFailed::class]);

        $this->makeJob()->failed(new RuntimeException('worker crashed'));

        Event::assertDispatched(TourOptimizationFailed::class, function (TourOptimizationFailed $event): bool {
            return $event->jobUuid === 'job-1' && $event->error['code'] === 'job_failed';
        });

        $this->assertSame('failed', app(TourCache::class)->getJobStatus('job-1')['status']);
    }

    public function test_job_releases_active_job_lock_on_success(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response(['OPTIMIZATION' => [0, 1], 'STEPS_DISTANCES' => ['TOTAL' => 1], 'STEPS_DURATIONS' => ['TOTAL' => 1]])]);

        $cache = app(TourCache::class);
        $cache->claimActiveJob($this->user->id, 'trucking', true, 'hash-1', 'job-1');

        $this->runJob($this->makeJob());

        // Lock cleared so a later identical request is served from cache / re-dispatches.
        $this->assertNull($cache->getActiveJob($this->user->id, 'trucking', true, 'hash-1'));
    }

    public function test_job_releases_active_job_lock_on_failure(): void
    {
        Event::fake();
        Http::fake(['*' => Http::response('', 500)]);

        $cache = app(TourCache::class);
        $cache->claimActiveJob($this->user->id, 'trucking', true, 'hash-1', 'job-1');

        $this->runJob($this->makeJob());

        $this->assertNull($cache->getActiveJob($this->user->id, 'trucking', true, 'hash-1'));
    }

    public function test_crash_callback_releases_active_job_lock(): void
    {
        Event::fake();

        $cache = app(TourCache::class);
        $cache->claimActiveJob($this->user->id, 'trucking', true, 'hash-1', 'job-1');

        $this->makeJob()->failed(new RuntimeException('worker crashed'));

        $this->assertNull($cache->getActiveJob($this->user->id, 'trucking', true, 'hash-1'));
    }
}
