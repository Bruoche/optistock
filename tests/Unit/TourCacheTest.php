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
        $this->assertSame('tour:trucking:closed:abc', $this->cache->tourKey('trucking', true, 'abc'));
        $this->assertSame('tour:trucking:open:abc', $this->cache->tourKey('trucking', false, 'abc'));
        $this->assertSame('tour:status:uuid-1', $this->cache->jobStatusKey('uuid-1'));
        $this->assertSame('tour:active:7:trucking:closed:abc', $this->cache->activeJobKey(7, 'trucking', true, 'abc'));
    }

    public function test_tour_round_trips(): void
    {
        $tour = ['ordered_stops' => [], 'total_distance_m' => 100, 'total_duration_s' => 60];

        $this->assertNull($this->cache->getTour('trucking', true, 'hash'));

        $this->cache->putTour('trucking', true, 'hash', $tour);

        $this->assertSame($tour, $this->cache->getTour('trucking', true, 'hash'));
    }

    public function test_tour_is_shared_across_users(): void
    {
        // The tour is a pure function of the coordinate set + mode + shape, so it is
        // keyed by those only — any user submitting the same stops/mode/shape reuses it.
        $tour = ['ordered_stops' => [], 'total_distance_m' => 1];

        $this->cache->putTour('trucking', true, 'hash', $tour);

        $this->assertSame($tour, $this->cache->getTour('trucking', true, 'hash'));
    }

    public function test_tours_are_separated_by_mode(): void
    {
        // Same coordinates, different mode = a different tour (003 — no cross-mode hit).
        $this->assertNotSame(
            $this->cache->tourKey('trucking', true, 'hash'),
            $this->cache->tourKey('walking', true, 'hash'),
        );

        $truckingTour = ['ordered_stops' => [], 'total_distance_m' => 4200];
        $this->cache->putTour('trucking', true, 'hash', $truckingTour);

        $this->assertNull($this->cache->getTour('walking', true, 'hash'));
        $this->assertSame($truckingTour, $this->cache->getTour('trucking', true, 'hash'));
    }

    public function test_tours_are_separated_by_shape(): void
    {
        // Same coordinates + mode, different loop shape = a different tour
        // (004 — a closed tour must never be served to an open request, or vice versa).
        $this->assertNotSame(
            $this->cache->tourKey('trucking', true, 'hash'),
            $this->cache->tourKey('trucking', false, 'hash'),
        );

        $closedTour = ['ordered_stops' => [], 'total_distance_m' => 4200];
        $this->cache->putTour('trucking', true, 'hash', $closedTour);

        $this->assertNull($this->cache->getTour('trucking', false, 'hash'));
        $this->assertSame($closedTour, $this->cache->getTour('trucking', true, 'hash'));
    }

    public function test_active_job_claim_is_exclusive_until_cleared(): void
    {
        $this->assertNull($this->cache->getActiveJob(1, 'trucking', true, 'hash'));

        $this->assertTrue($this->cache->claimActiveJob(1, 'trucking', true, 'hash', 'job-a'));
        // Second claim for the same set loses; the original owner stands.
        $this->assertFalse($this->cache->claimActiveJob(1, 'trucking', true, 'hash', 'job-b'));
        $this->assertSame('job-a', $this->cache->getActiveJob(1, 'trucking', true, 'hash'));

        $this->cache->releaseActiveJob(1, 'trucking', true, 'hash');
        $this->assertNull($this->cache->getActiveJob(1, 'trucking', true, 'hash'));
        // After clearing, a fresh set can be claimed again.
        $this->assertTrue($this->cache->claimActiveJob(1, 'trucking', true, 'hash', 'job-c'));
    }

    public function test_active_job_locks_are_separated_by_mode_and_shape(): void
    {
        // A trucking/closed optimization in flight must not block a walking one nor an
        // open one for the same coordinates — they are different jobs.
        $this->assertTrue($this->cache->claimActiveJob(1, 'trucking', true, 'hash', 'job-trucking'));
        $this->assertTrue($this->cache->claimActiveJob(1, 'walking', true, 'hash', 'job-walking'));
        $this->assertTrue($this->cache->claimActiveJob(1, 'trucking', false, 'hash', 'job-open'));

        $this->assertSame('job-trucking', $this->cache->getActiveJob(1, 'trucking', true, 'hash'));
        $this->assertSame('job-walking', $this->cache->getActiveJob(1, 'walking', true, 'hash'));
        $this->assertSame('job-open', $this->cache->getActiveJob(1, 'trucking', false, 'hash'));
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
