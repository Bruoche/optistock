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
     */
    public function optimize(int $userId, array $coordinates, string $mode): TourOptimizationResult
    {
        $normalizedCoordinates = $this->normalizer->normalize($coordinates);
        // sha256 of the canonical coordinates → order-independent cache key. The
        // mode is a separate cache dimension (a walking tour differs from a
        // trucking one), so it is carried alongside the hash, never folded into it.
        $coordinatesHash = hash('sha256', (string) json_encode($normalizedCoordinates));

        $cachedTour = $this->cache->getTour($mode, $coordinatesHash);

        if ($cachedTour !== null) {
            return TourOptimizationResult::ready($cachedTour);
        }

        $jobUuid = (string) Str::uuid();

        // Dedup concurrent identical requests. claimActiveJob atomically reserves
        // the slot: true = we won it and must dispatch, false = an identical
        // optimization (same user, mode, coordinates) is already running.
        $wonClaim = $this->cache->claimActiveJob($userId, $mode, $coordinatesHash, $jobUuid);

        if (! $wonClaim) {
            $runningJobUuid = $this->cache->getActiveJob($userId, $mode, $coordinatesHash);

            // Reuse the running job so we never fire a second multi-minute upstream call.
            if ($runningJobUuid !== null) {
                return TourOptimizationResult::pending($runningJobUuid);
            }

            // Rare race: that job released its slot between our failed claim and
            // this read. Fall through and dispatch a fresh job.
        }

        $this->cache->markPending($jobUuid);
        OptimizeTourJob::dispatch($jobUuid, $userId, $coordinatesHash, $normalizedCoordinates, $mode);

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
