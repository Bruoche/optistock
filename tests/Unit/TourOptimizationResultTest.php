<?php

namespace Tests\Unit;

use App\Services\TourOptimizationResult;
use LogicException;
use PHPUnit\Framework\TestCase;

class TourOptimizationResultTest extends TestCase
{
    public function test_ready_exposes_the_tour(): void
    {
        $tour = ['ordered_stops' => [], 'total_distance_m' => 10, 'total_duration_s' => 5];

        $result = TourOptimizationResult::ready($tour);

        $this->assertTrue($result->isReady);
        $this->assertSame($tour, $result->tour());
    }

    public function test_pending_exposes_the_job_uuid(): void
    {
        $result = TourOptimizationResult::pending('job-1');

        $this->assertFalse($result->isReady);
        $this->assertSame('job-1', $result->jobUuid());
    }

    public function test_reading_job_uuid_from_a_ready_result_throws(): void
    {
        $this->expectException(LogicException::class);

        TourOptimizationResult::ready(['total_distance_m' => 1])->jobUuid();
    }

    public function test_reading_tour_from_a_pending_result_throws(): void
    {
        $this->expectException(LogicException::class);

        TourOptimizationResult::pending('job-1')->tour();
    }

    public function test_failed_exposes_the_error(): void
    {
        $error = ['code' => 'persist_failed', 'message' => 'nope'];

        $result = TourOptimizationResult::failed($error);

        $this->assertFalse($result->isReady);
        $this->assertTrue($result->isFailed());
        $this->assertSame($error, $result->error());
    }

    public function test_ready_and_pending_results_are_not_failed(): void
    {
        $this->assertFalse(TourOptimizationResult::ready(['total_distance_m' => 1])->isFailed());
        $this->assertFalse(TourOptimizationResult::pending('job-1')->isFailed());
    }

    public function test_reading_error_from_a_ready_result_throws(): void
    {
        $this->expectException(LogicException::class);

        TourOptimizationResult::ready(['total_distance_m' => 1])->error();
    }
}
