<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Owns all cache reads/writes for route optimization.
 *
 * Two distinct entries:
 *  - Result cache  `route:opt:{userId}:{hash}`  — the optimized route, kept for
 *    24h so identical coordinate sets are served instantly (HTTP 200 cache hit).
 *  - Job status    `route:opt:pending:{jobUuid}` — tracks a single async request
 *    so the polling endpoint (WebSocket fallback) can report pending/done/failed.
 *    Kept for 1h: long enough to outlive processing, short enough to self-clean.
 */
class RouteCache
{
    private const RESULT_TTL_SECONDS = 86400; // 24 hours

    private const STATUS_TTL_SECONDS = 3600;  // 1 hour

    public function __construct(private readonly CacheRepository $cache) {}

    public function resultKey(int $userId, string $hash): string
    {
        return "route:opt:{$userId}:{$hash}";
    }

    public function statusKey(string $jobUuid): string
    {
        return "route:opt:pending:{$jobUuid}";
    }

    /**
     * @return array{
     *     ordered_stops: array<int, array{lat: float, lng: float, order: int}>,
     *     total_distance_m: int,
     *     total_duration_s: int
     * }|null
     */
    public function getResult(int $userId, string $hash): ?array
    {
        return $this->cache->get($this->resultKey($userId, $hash));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function putResult(int $userId, string $hash, array $data): void
    {
        $this->cache->put($this->resultKey($userId, $hash), $data, self::RESULT_TTL_SECONDS);
    }

    public function markPending(string $jobUuid): void
    {
        $this->cache->put(
            $this->statusKey($jobUuid),
            ['status' => 'pending'],
            self::STATUS_TTL_SECONDS,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function markDone(string $jobUuid, array $data): void
    {
        $this->cache->put(
            $this->statusKey($jobUuid),
            ['status' => 'done', 'data' => $data],
            self::STATUS_TTL_SECONDS,
        );
    }

    /**
     * @param  array{code: string, message: string}  $error
     */
    public function markFailed(string $jobUuid, array $error): void
    {
        $this->cache->put(
            $this->statusKey($jobUuid),
            ['status' => 'failed', 'error' => $error],
            self::STATUS_TTL_SECONDS,
        );
    }

    /**
     * @return array{status: string, data?: array<string, mixed>, error?: array{code: string, message: string}}|null
     */
    public function getStatus(string $jobUuid): ?array
    {
        return $this->cache->get($this->statusKey($jobUuid));
    }
}
