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
 *  - In-flight lock `route:opt:inflight:{userId}:{hash}` — maps a coordinate set
 *    already being optimized to its jobUuid, so concurrent identical requests
 *    reuse the running job instead of firing a second (multi-minute) upstream
 *    call. TTL covers the worst-case job runtime so a crashed worker self-heals.
 */
class RouteCache
{
    private const RESULT_TTL_SECONDS = 86400; // 24 hours

    private const STATUS_TTL_SECONDS = 3600;  // 1 hour

    /** ≥ worst-case job runtime; auto-expires if a worker dies without clearing. */
    private const INFLIGHT_TTL_SECONDS = 1380;

    public function __construct(private readonly CacheRepository $cache) {}

    public function resultKey(int $userId, string $hash): string
    {
        return "route:opt:{$userId}:{$hash}";
    }

    public function statusKey(string $jobUuid): string
    {
        return "route:opt:pending:{$jobUuid}";
    }

    public function inflightKey(int $userId, string $hash): string
    {
        return "route:opt:inflight:{$userId}:{$hash}";
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

    /**
     * Atomically claim the in-flight slot for a coordinate set. Returns true if
     * this caller won the slot (and should dispatch the job), false if another
     * request already owns it (the caller should reuse {@see getInflight}).
     */
    public function claimInflight(int $userId, string $hash, string $jobUuid): bool
    {
        return $this->cache->add(
            $this->inflightKey($userId, $hash),
            $jobUuid,
            self::INFLIGHT_TTL_SECONDS,
        );
    }

    public function getInflight(int $userId, string $hash): ?string
    {
        return $this->cache->get($this->inflightKey($userId, $hash));
    }

    public function clearInflight(int $userId, string $hash): void
    {
        $this->cache->forget($this->inflightKey($userId, $hash));
    }
}
