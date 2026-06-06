<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Owns all cache reads/writes for tour optimization.
 *
 * Three distinct entries:
 *  - Tour cache    `tour:{userId}:{hash}`         — the optimized tour, kept for
 *    24h so identical coordinate sets are served instantly (HTTP 200 cache hit).
 *  - Job status    `tour:status:{jobUuid}`        — tracks a single async request
 *    so the status endpoint (WebSocket fallback) can report pending/done/failed.
 *    Kept for 1h: long enough to outlive processing, short enough to self-clean.
 *  - Active-job lock `tour:active:{userId}:{hash}` — maps a coordinate set
 *    already being optimized to its jobUuid, so concurrent identical requests
 *    reuse the running job instead of firing a second (multi-minute) upstream
 *    call. TTL covers the worst-case job runtime so a crashed worker self-heals.
 */
class TourCache
{
    private const TOUR_TTL_SECONDS = 86400; // 24 hours

    private const STATUS_TTL_SECONDS = 3600;  // 1 hour

    /** ≥ worst-case job runtime; auto-expires if a worker dies without clearing. */
    private const ACTIVE_JOB_TTL_SECONDS = 1380;

    public function __construct(private readonly CacheRepository $cache) {}

    public function tourKey(int $userId, string $coordinatesHash): string
    {
        return "tour:{$userId}:{$coordinatesHash}";
    }

    public function jobStatusKey(string $jobUuid): string
    {
        return "tour:status:{$jobUuid}";
    }

    public function activeJobKey(int $userId, string $coordinatesHash): string
    {
        return "tour:active:{$userId}:{$coordinatesHash}";
    }

    /**
     * @return array{
     *     ordered_stops: array<int, array{lat: float, lng: float, order: int}>,
     *     total_distance_m: int,
     *     total_duration_s: int
     * }|null
     */
    public function getTour(int $userId, string $coordinatesHash): ?array
    {
        return $this->cache->get($this->tourKey($userId, $coordinatesHash));
    }

    /**
     * @param  array<string, mixed>  $tour
     */
    public function putTour(int $userId, string $coordinatesHash, array $tour): void
    {
        $this->cache->put($this->tourKey($userId, $coordinatesHash), $tour, self::TOUR_TTL_SECONDS);
    }

    public function markPending(string $jobUuid): void
    {
        $this->cache->put(
            $this->jobStatusKey($jobUuid),
            ['status' => 'pending'],
            self::STATUS_TTL_SECONDS,
        );
    }

    /**
     * @param  array<string, mixed>  $tour
     */
    public function markDone(string $jobUuid, array $tour): void
    {
        $this->cache->put(
            $this->jobStatusKey($jobUuid),
            ['status' => 'done', 'data' => $tour],
            self::STATUS_TTL_SECONDS,
        );
    }

    /**
     * @param  array{code: string, message: string}  $error
     */
    public function markFailed(string $jobUuid, array $error): void
    {
        $this->cache->put(
            $this->jobStatusKey($jobUuid),
            ['status' => 'failed', 'error' => $error],
            self::STATUS_TTL_SECONDS,
        );
    }

    /**
     * @return array{status: string, data?: array<string, mixed>, error?: array{code: string, message: string}}|null
     */
    public function getJobStatus(string $jobUuid): ?array
    {
        return $this->cache->get($this->jobStatusKey($jobUuid));
    }

    /**
     * Atomically claim the active-job slot for a coordinate set. Returns true if
     * this caller won the slot (and should dispatch the job), false if another
     * request already owns it (the caller should reuse {@see getActiveJob}).
     */
    public function claimActiveJob(int $userId, string $coordinatesHash, string $jobUuid): bool
    {
        return $this->cache->add(
            $this->activeJobKey($userId, $coordinatesHash),
            $jobUuid,
            self::ACTIVE_JOB_TTL_SECONDS,
        );
    }

    public function getActiveJob(int $userId, string $coordinatesHash): ?string
    {
        return $this->cache->get($this->activeJobKey($userId, $coordinatesHash));
    }

    public function releaseActiveJob(int $userId, string $coordinatesHash): void
    {
        $this->cache->forget($this->activeJobKey($userId, $coordinatesHash));
    }
}
