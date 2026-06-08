<?php

namespace App\Services;

use App\Jobs\OptimizeTourJob;
use Illuminate\Support\Str;

/**
 * Orchestrates a tour-optimization request: serve from cache when possible,
 * otherwise dedup against an already-running job or dispatch a new one. Callers
 * get a {@see TourOptimizationResult} and never need to know whether the pending
 * job was freshly queued or reused.
 */
class TourOptimizationService
{
    public function __construct(
        private readonly CoordinateNormalizer $normalizer,
        private readonly TourCache $cache,
    ) {}

    /**
     * @param  array<int, array{0: int|float|string, 1: int|float|string}>  $coordinates
     * @param  string  $mode  Travel mode driving both the optimization and (later) the geometry trace.
     * @param  bool  $loop  Tour shape: true = closed loop (return to origin), false = open one-way (004).
     */
    public function optimize(int $userId, array $coordinates, string $mode, bool $loop): TourOptimizationResult
    {
        $normalizedCoordinates = $this->normalizer->normalize($coordinates);
        $coordinatesHash = hash('sha256', (string) json_encode($normalizedCoordinates)); // -> order-independent cache key.
        $cachedTour = $this->cache->getTour($mode, $loop, $coordinatesHash);
        if ($cachedTour !== null) {
            return TourOptimizationResult::ready($cachedTour);
        }
        $jobUuid = (string) Str::uuid();
        $wonClaim = $this->cache->claimActiveJob($userId, $mode, $loop, $coordinatesHash, $jobUuid);
        if (! $wonClaim) { // Job already claimed by another worker
            $runningJobUuid = $this->cache->getActiveJob($userId, $mode, $loop, $coordinatesHash);
            // Reuse the running job so we never fire a second multi-minute upstream call.
            if ($runningJobUuid !== null) {
                return TourOptimizationResult::pending($runningJobUuid);
            }
            // Rare race: that job released its slot between our failed claim and
            // this read. Fall through and dispatch a fresh job.
        }
        $this->cache->markPending($jobUuid);
        OptimizeTourJob::dispatch($jobUuid, $userId, $coordinatesHash, $normalizedCoordinates, $mode, $loop);
        return TourOptimizationResult::pending($jobUuid);
    }

    /**
     * Current status of a queued optimization (pending/done/failed), or null if
     * unknown/expired. Backs the WebSocket polling fallback.
     *
     * @return array{status: string, data?: array<string, mixed>, error?: array{code: string, message: string}}|null
     */
    public function jobStatus(string $jobUuid): ?array
    {
        return $this->cache->getJobStatus($jobUuid);
    }
}
