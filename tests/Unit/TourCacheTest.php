<?php

namespace Tests\Unit;

use App\Services\TourCache;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

class TourCacheTest extends TestCase
{
    private TourCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new TourCache(new Repository(new ArrayStore));
    }

    public function test_keys_are_namespaced(): void
    {
        $this->assertSame('tour:abc', $this->cache->tourKey('abc'));
        $this->assertSame('tour:status:uuid-1', $this->cache->jobStatusKey('uuid-1'));
        $this->assertSame('tour:active:7:abc', $this->cache->activeJobKey(7, 'abc'));
    }

    public function test_tour_round_trips(): void
    {
        $tour = ['ordered_stops' => [], 'total_distance_m' => 100, 'total_duration_s' => 60];

        $this->assertNull($this->cache->getTour('hash'));

        $this->cache->putTour('hash', $tour);

        $this->assertSame($tour, $this->cache->getTour('hash'));
    }

    public function test_tour_is_shared_across_users(): void
    {
        // The tour is a pure function of the coordinate set, so it is keyed by
        // hash only — any user submitting the same stops reuses the cached tour.
        $tour = ['ordered_stops' => [], 'total_distance_m' => 1];

        $this->cache->putTour('hash', $tour);

        $this->assertSame($tour, $this->cache->getTour('hash'));
    }

    public function test_active_job_claim_is_exclusive_until_cleared(): void
    {
        $this->assertNull($this->cache->getActiveJob(1, 'hash'));

        $this->assertTrue($this->cache->claimActiveJob(1, 'hash', 'job-a'));
        // Second claim for the same set loses; the original owner stands.
        $this->assertFalse($this->cache->claimActiveJob(1, 'hash', 'job-b'));
        $this->assertSame('job-a', $this->cache->getActiveJob(1, 'hash'));

        $this->cache->releaseActiveJob(1, 'hash');
        $this->assertNull($this->cache->getActiveJob(1, 'hash'));
        // After clearing, a fresh set can be claimed again.
        $this->assertTrue($this->cache->claimActiveJob(1, 'hash', 'job-c'));
    }

    public function test_status_transitions_pending_done_failed(): void
    {
        $this->assertNull($this->cache->getJobStatus('job-1'));

        $this->cache->markPending('job-1');
        $this->assertSame(['status' => 'pending'], $this->cache->getJobStatus('job-1'));

        $this->cache->markDone('job-1', ['total_distance_m' => 5]);
        $this->assertSame(
            ['status' => 'done', 'data' => ['total_distance_m' => 5]],
            $this->cache->getJobStatus('job-1'),
        );

        $this->cache->markFailed('job-1', ['code' => 'timeout', 'message' => 'slow']);
        $this->assertSame(
            ['status' => 'failed', 'error' => ['code' => 'timeout', 'message' => 'slow']],
            $this->cache->getJobStatus('job-1'),
        );
    }
}
