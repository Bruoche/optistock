<?php

namespace Tests\Unit;

use App\Services\RouteCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

class RouteCacheTest extends TestCase
{
    private RouteCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new RouteCache(new Repository(new ArrayStore));
    }

    public function test_keys_are_namespaced_by_user_and_job(): void
    {
        $this->assertSame('route:opt:7:abc', $this->cache->resultKey(7, 'abc'));
        $this->assertSame('route:opt:pending:uuid-1', $this->cache->statusKey('uuid-1'));
    }

    public function test_result_round_trips(): void
    {
        $data = ['ordered_stops' => [], 'total_distance_m' => 100, 'total_duration_s' => 60];

        $this->assertNull($this->cache->getResult(1, 'hash'));

        $this->cache->putResult(1, 'hash', $data);

        $this->assertSame($data, $this->cache->getResult(1, 'hash'));
    }

    public function test_result_is_isolated_per_user(): void
    {
        $this->cache->putResult(1, 'hash', ['total_distance_m' => 1]);

        $this->assertNull($this->cache->getResult(2, 'hash'));
    }

    public function test_inflight_claim_is_exclusive_until_cleared(): void
    {
        $this->assertNull($this->cache->getInflight(1, 'hash'));

        $this->assertTrue($this->cache->claimInflight(1, 'hash', 'job-a'));
        // Second claim for the same set loses; the original owner stands.
        $this->assertFalse($this->cache->claimInflight(1, 'hash', 'job-b'));
        $this->assertSame('job-a', $this->cache->getInflight(1, 'hash'));

        $this->cache->clearInflight(1, 'hash');
        $this->assertNull($this->cache->getInflight(1, 'hash'));
        // After clearing, a fresh set can be claimed again.
        $this->assertTrue($this->cache->claimInflight(1, 'hash', 'job-c'));
    }

    public function test_status_transitions_pending_done_failed(): void
    {
        $this->assertNull($this->cache->getStatus('job-1'));

        $this->cache->markPending('job-1');
        $this->assertSame(['status' => 'pending'], $this->cache->getStatus('job-1'));

        $this->cache->markDone('job-1', ['total_distance_m' => 5]);
        $this->assertSame(
            ['status' => 'done', 'data' => ['total_distance_m' => 5]],
            $this->cache->getStatus('job-1'),
        );

        $this->cache->markFailed('job-1', ['code' => 'timeout', 'message' => 'slow']);
        $this->assertSame(
            ['status' => 'failed', 'error' => ['code' => 'timeout', 'message' => 'slow']],
            $this->cache->getStatus('job-1'),
        );
    }
}
