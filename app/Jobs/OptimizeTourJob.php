<?php

namespace App\Jobs;

use App\Events\TourOptimizationFailed;
use App\Events\TourOptimized;
use App\Exceptions\TourOptimizationException;
use App\Services\OpenStreetTspClient;
use App\Services\TourCache;
use App\Services\TourOptimizationService;
use App\Services\TourRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
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
     * @param  array<string, list<int>>  $durationByCoord  normalized coord → per-stop duration queue (for persistence)
     */
    public function __construct(
        public readonly string $jobUuid,
        public readonly int $userId,
        public readonly string $coordinatesHash,
        public readonly array $coordinates,
        public readonly array $durationByCoord,
        public readonly string $mode,
        public readonly bool $loop,
    ) {
        $this->timeout = (int) config('services.openstreet.job_timeout', 1260);
    }

    public function handle(OpenStreetTspClient $client, TourCache $cache, TourRecorder $recorder): void
    {
        $tourShape = $this->loop ? 'closed' : 'open';
        try {
            $tour = $client->optimize($this->coordinates, $this->mode, $tourShape);
        } catch (TourOptimizationException $e) {
            Log::warning('Tour optimization failed', [
                'job_uuid' => $this->jobUuid,
                'user_id' => $this->userId,
                'coordinates_hash' => $this->coordinatesHash,
                'mode' => $this->mode,
                'loop' => $this->loop,
                'error' => $e->toPayload(),
            ]);
            $cache->releaseActiveJob($this->userId, $this->mode, $this->loop, $this->coordinatesHash);
            $cache->markFailed($this->jobUuid, $e->toPayload());
            TourOptimizationFailed::dispatch($this->userId, $this->jobUuid, $e->toPayload());

            return;
        }
        $cache->releaseActiveJob($this->userId, $this->mode, $this->loop, $this->coordinatesHash);
        // Cache the (expensive) result BEFORE persisting, so a save failure leaves a
        // cache hit for a retry that re-attempts only the save (D10).
        $cache->putTour($this->mode, $this->loop, $this->coordinatesHash, $tour);

        try {
            $persistedTour = $recorder->record(
                $this->userId,
                $this->mode,
                $this->loop,
                $tour['ordered_stops'],
                $this->durationByCoord,
                $tour['total_distance_m'],
                $tour['total_duration_s'],
            );
        } catch (Throwable $e) {
            // A successful optimization that could not be saved: surface a distinct
            // persist failure rather than letting it crash into failed() as a generic
            // error (FR-014). The result stays cached for a retry.
            Log::error('Tour persistence failed', [
                'job_uuid' => $this->jobUuid,
                'user_id' => $this->userId,
                'coordinates_hash' => $this->coordinatesHash,
                'error' => $e->getMessage(),
            ]);
            $error = TourOptimizationService::persistError();
            $cache->markFailed($this->jobUuid, $error);
            TourOptimizationFailed::dispatch($this->userId, $this->jobUuid, $error);

            return;
        }

        $data = $tour + ['id' => $persistedTour->id];
        $cache->markDone($this->jobUuid, $data);
        TourOptimized::dispatch($this->userId, $this->jobUuid, $data);
    }

    /**
     * Safety net for unhandled failures (e.g. timeout, worker crash) so the
     * frontend is always notified instead of hanging.
     */
    public function failed(?Throwable $e): void
    {
        $error = ['code' => 'job_failed', 'message' => $e?->getMessage() ?? 'Tour optimization job failed.'];
        Log::error('Tour optimization job crashed', [
            'job_uuid' => $this->jobUuid,
            'user_id' => $this->userId,
            'coordinates_hash' => $this->coordinatesHash,
            'mode' => $this->mode,
            'loop' => $this->loop,
            'exception' => $e,
        ]);
        $cache = app(TourCache::class);
        $cache->releaseActiveJob($this->userId, $this->mode, $this->loop, $this->coordinatesHash);
        $cache->markFailed($this->jobUuid, $error);
        TourOptimizationFailed::dispatch($this->userId, $this->jobUuid, $error);
    }
}
