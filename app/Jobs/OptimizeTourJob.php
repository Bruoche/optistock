<?php

namespace App\Jobs;

use App\Events\TourOptimizationFailed;
use App\Events\TourOptimized;
use App\Exceptions\TourOptimizationException;
use App\Services\OpenStreetTspClient;
use App\Services\TourCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Calls the (slow, unreliable) OpenStreet TSP API off the request cycle.
 *
 * On success: caches the tour for 24h, records job status, and broadcasts
 * {@see TourOptimized}. On a handled API failure or an unhandled crash
 * ({@see failed()}), records the failure and broadcasts
 * {@see TourOptimizationFailed} so the frontend never waits forever.
 */
class OptimizeTourJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Hard ceiling for the whole job, covering ALL upstream attempts: the client
     * makes up to (retries + 1) calls, each up to its read timeout, plus backoff.
     * This must therefore sit above (retries + 1) × client read timeout, and the
     * queue connection's retry_after must sit above this. Set from config so the
     * limits stay in sync. The worker must run with `--timeout` >= this value and
     * `retry_after` > the worker timeout.
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
        public readonly string $coordinatesHash,
        public readonly array $coordinates,
    ) {
        $this->timeout = (int) config('services.openstreet.job_timeout', 1260);
    }

    public function handle(OpenStreetTspClient $client, TourCache $cache): void
    {
        try {
            $tour = $client->optimize($this->coordinates);
        } catch (TourOptimizationException $e) {
            $cache->clearInflight($this->userId, $this->coordinatesHash);
            $cache->markFailed($this->jobUuid, $e->toPayload());
            TourOptimizationFailed::dispatch($this->userId, $this->jobUuid, $e->toPayload());

            return;
        }

        $cache->clearInflight($this->userId, $this->coordinatesHash);
        $cache->putTour($this->userId, $this->coordinatesHash, $tour);
        $cache->markDone($this->jobUuid, $tour);
        TourOptimized::dispatch($this->userId, $this->jobUuid, $tour);
    }

    /**
     * Safety net for unhandled failures (e.g. timeout, worker crash) so the
     * frontend is always notified instead of hanging.
     */
    public function failed(?Throwable $e): void
    {
        $error = ['code' => 'job_failed', 'message' => $e?->getMessage() ?? 'Tour optimization job failed.'];

        $cache = app(TourCache::class);
        $cache->clearInflight($this->userId, $this->coordinatesHash);
        $cache->markFailed($this->jobUuid, $error);
        TourOptimizationFailed::dispatch($this->userId, $this->jobUuid, $error);
    }
}
