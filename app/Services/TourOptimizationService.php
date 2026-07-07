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
    public function optimize(int $userId, array $stops, string $mode, bool $loop, ?int $editTourId = null): TourOptimizationResult
    {
        $inputs = $this->buildOptimizationInputs($stops);

        return $this->serveCachedTour($userId, $mode, $loop, $inputs, $editTourId)
            ?? $this->dispatchOptimization($userId, $mode, $loop, $inputs, $editTourId);
    }

    /**
     * @param  array<int, array{lat: int|float|string, lng: int|float|string, duration_s: int}>  $stops
     */
    private function buildOptimizationInputs(array $stops): OptimizationInputs
    {
        $coordinates = array_map(static fn (array $stop): array => [$stop['lat'], $stop['lng']], $stops);
        $normalizedCoordinates = $this->normalizer->normalize($coordinates);

        return new OptimizationInputs(
            $normalizedCoordinates,
            hash('sha256', (string) json_encode($normalizedCoordinates)),
            $this->mapDurationsByCoordinate($stops),
        );
    }

    /** A cached (already-optimized) tour, persisted and returned ready; null on a cache miss. */
    private function serveCachedTour(int $userId, string $mode, bool $loop, OptimizationInputs $inputs, ?int $editTourId): ?TourOptimizationResult
    {
        $cachedTour = $this->cache->getTour($mode, $loop, $inputs->coordinatesHash);
        if ($cachedTour === null) {
            return null;
        }

        return $this->recordCacheHit($userId, $mode, $loop, $inputs->coordinatesHash, $cachedTour, $inputs->durationsByCoordinate, $editTourId);
    }

    /** Queue (or reuse) the upstream optimization job and return the pending handle. */
    private function dispatchOptimization(int $userId, string $mode, bool $loop, OptimizationInputs $inputs, ?int $editTourId): TourOptimizationResult
    {
        $jobUuid = (string) Str::uuid();
        $wonClaim = $this->cache->claimActiveJob($userId, $mode, $loop, $inputs->coordinatesHash, $jobUuid);
        if (! $wonClaim) {
            $runningJobUuid = $this->cache->getActiveJob($userId, $mode, $loop, $inputs->coordinatesHash);
            // Reuse the running job so we never fire a second multi-minute upstream call.
            if ($runningJobUuid !== null) {
                return TourOptimizationResult::pending($runningJobUuid);
            }
            // Rare race: the job released its slot between our failed claim and this read.
        }
        $this->cache->markPending($jobUuid);
        OptimizeTourJob::dispatch($jobUuid, $userId, $inputs->coordinatesHash, $inputs->normalizedCoordinates, $inputs->durationsByCoordinate, $mode, $loop, $editTourId);

        return TourOptimizationResult::pending($jobUuid);
    }

    /**
     * Persist a cache-hit tour and thread its id back. A save failure is logged and
     * surfaced as `persist_failed` rather than becoming an opaque 500 (D10/FR-014).
     *
     * @param  array<string, mixed>  $cachedTour
     * @param  array<string, list<int>>  $durationsByCoordinate
     */
    private function recordCacheHit(
        int $userId,
        string $mode,
        bool $loop,
        string $coordinatesHash,
        array $cachedTour,
        array $durationsByCoordinate,
        ?int $editTourId = null,
    ): TourOptimizationResult {
        try {
            $tour = $this->recorder->record(
                $userId,
                $mode,
                $loop,
                $cachedTour['ordered_stops'] ?? [],
                $durationsByCoordinate,
                $cachedTour['total_distance_m'] ?? null,
                $cachedTour['total_duration_s'] ?? null,
                $editTourId,
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
     * Map each normalized coordinate to its queue of delivery durations, used to
     * re-attach each ordered stop's duration after the TSP reorder. Keyed by the same
     * rounding as the cache key; a queue per coordinate keeps duplicates unambiguous.
     *
     * @param  array<int, array{lat: int|float|string, lng: int|float|string, duration_s: int}>  $stops
     * @return array<string, list<int>>
     */
    private function mapDurationsByCoordinate(array $stops): array
    {
        $durationsByCoordinate = [];
        foreach ($stops as $stop) {
            $key = TourRecorder::coordinateKey((float) $stop['lat'], (float) $stop['lng']);
            $durationsByCoordinate[$key][] = (int) $stop['duration_s'];
        }

        return $durationsByCoordinate;
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
