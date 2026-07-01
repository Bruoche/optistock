<?php

namespace App\Services;

use App\Jobs\OptimizeTourJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates a tour-optimization request: serve from cache when possible,
 * otherwise dedup against an already-running job or dispatch a new one. On a cache
 * hit the tour is persisted synchronously; a persistence failure is surfaced (not
 * a silent unsaved route — D10/FR-014). Callers get a {@see TourOptimizationResult}
 * and never need to know whether the pending job was freshly queued or reused.
 */
class TourOptimizationService
{
    public function __construct(
        private readonly CoordinateNormalizer $normalizer,
        private readonly TourCache $cache,
        private readonly TourRecorder $recorder,
    ) {}

    /**
     * @param  array<int, array{lat: int|float|string, lng: int|float|string, duration_s: int}>  $stops
     * @param  string  $mode  Travel mode driving both the optimization and (later) the geometry trace.
     * @param  bool  $loop  Tour shape: true = closed loop (return to origin), false = open one-way (004).
     */
    public function optimize(int $userId, array $stops, string $mode, bool $loop): TourOptimizationResult
    {
        $coordinates = array_map(static fn (array $stop): array => [$stop['lat'], $stop['lng']], $stops);
        $durationByCoord = $this->durationByCoord($stops);

        $normalizedCoordinates = $this->normalizer->normalize($coordinates);
        $coordinatesHash = hash('sha256', (string) json_encode($normalizedCoordinates));

        $cachedTour = $this->cache->getTour($mode, $loop, $coordinatesHash);
        if ($cachedTour !== null) {
            return $this->recordCacheHit($userId, $mode, $loop, $coordinatesHash, $cachedTour, $durationByCoord);
        }

        $jobUuid = (string) Str::uuid();
        $wonClaim = $this->cache->claimActiveJob($userId, $mode, $loop, $coordinatesHash, $jobUuid);
        if (! $wonClaim) {
            $runningJobUuid = $this->cache->getActiveJob($userId, $mode, $loop, $coordinatesHash);
            // Reuse the running job so we never fire a second multi-minute upstream call.
            if ($runningJobUuid !== null) {
                return TourOptimizationResult::pending($runningJobUuid);
            }
            // Rare race: the job released its slot between our failed claim and this read.
        }
        $this->cache->markPending($jobUuid);
        OptimizeTourJob::dispatch($jobUuid, $userId, $coordinatesHash, $normalizedCoordinates, $durationByCoord, $mode, $loop);

        return TourOptimizationResult::pending($jobUuid);
    }

    /**
     * Persist a cache-hit tour and thread its id back. A save failure is logged and
     * surfaced as `persist_failed` rather than becoming an opaque 500 (D10/FR-014).
     *
     * @param  array<string, mixed>  $cachedTour
     * @param  array<string, list<int>>  $durationByCoord
     */
    private function recordCacheHit(
        int $userId,
        string $mode,
        bool $loop,
        string $coordinatesHash,
        array $cachedTour,
        array $durationByCoord,
    ): TourOptimizationResult {
        try {
            $tour = $this->recorder->record(
                $userId,
                $mode,
                $loop,
                $cachedTour['ordered_stops'] ?? [],
                $durationByCoord,
                $cachedTour['total_distance_m'] ?? null,
                $cachedTour['total_duration_s'] ?? null,
            );
        } catch (Throwable $e) {
            Log::error('Tour persistence failed (cache hit)', [
                'user_id' => $userId,
                'coordinates_hash' => $coordinatesHash,
                'mode' => $mode,
                'loop' => $loop,
                'error' => $e->getMessage(),
            ]);

            return TourOptimizationResult::failed(self::persistError());
        }

        return TourOptimizationResult::ready($cachedTour + ['id' => $tour->id]);
    }

    /**
     * Build the normalized-coordinate → duration queue map used to re-attach each
     * ordered stop's delivery duration after the TSP reorder. Keyed by the same
     * rounding as the cache key; a queue per coordinate keeps duplicates unambiguous.
     *
     * @param  array<int, array{lat: int|float|string, lng: int|float|string, duration_s: int}>  $stops
     * @return array<string, list<int>>
     */
    private function durationByCoord(array $stops): array
    {
        $map = [];
        foreach ($stops as $stop) {
            $key = TourRecorder::coordinateKey((float) $stop['lat'], (float) $stop['lng']);
            $map[$key][] = (int) $stop['duration_s'];
        }

        return $map;
    }

    /**
     * @return array{code: string, message: string}
     */
    public static function persistError(): array
    {
        return [
            'code' => 'persist_failed',
            'message' => 'The optimized route could not be saved. Please try again.',
        ];
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
