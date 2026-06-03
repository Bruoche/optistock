<?php

namespace App\Jobs;

use App\Events\RouteOptimizationFailed;
use App\Events\RouteOptimized;
use App\Exceptions\RouteOptimizationException;
use App\Services\OpenStreetTspClient;
use App\Services\RouteCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Calls the (slow, unreliable) OpenStreet TSP API off the request cycle.
 *
 * On success: caches the route for 24h, records job status, and broadcasts
 * {@see RouteOptimized}. On a handled API failure or an unhandled crash
 * ({@see failed()}), records the failure and broadcasts
 * {@see RouteOptimizationFailed} so the frontend never waits forever.
 */
class OptimizeRouteJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Hard ceiling on a single attempt; sits above the client's read timeout
     * and below the queue connection's retry_after. Set from config so all
     * three limits stay in sync. The worker must run with
     * `--timeout` >= this value and `retry_after` > the worker timeout.
     */
    public int $timeout;

    /** One attempt — the client already retries the upstream call internally. */
    public int $tries = 1;

    /**
     * @param  array<int, array{lat: float, lng: float}>  $coordinates
     */
    public function __construct(
        public readonly string $jobUuid,
        public readonly int $userId,
        public readonly string $hash,
        public readonly array $coordinates,
    ) {
        $this->timeout = (int) config('services.openstreet.job_timeout', 660);
    }

    public function handle(OpenStreetTspClient $client, RouteCache $cache): void
    {
        try {
            $result = $client->optimize($this->coordinates);
        } catch (RouteOptimizationException $e) {
            $cache->markFailed($this->jobUuid, $e->toPayload());
            RouteOptimizationFailed::dispatch($this->userId, $this->jobUuid, $e->toPayload());

            return;
        }

        $cache->putResult($this->userId, $this->hash, $result);
        $cache->markDone($this->jobUuid, $result);
        RouteOptimized::dispatch($this->userId, $this->jobUuid, $result);
    }

    /**
     * Safety net for unhandled failures (e.g. timeout, worker crash) so the
     * frontend is always notified instead of hanging.
     */
    public function failed(?Throwable $e): void
    {
        $error = ['code' => 'job_failed', 'message' => $e?->getMessage() ?? 'Route optimization job failed.'];

        app(RouteCache::class)->markFailed($this->jobUuid, $error);
        RouteOptimizationFailed::dispatch($this->userId, $this->jobUuid, $error);
    }
}
