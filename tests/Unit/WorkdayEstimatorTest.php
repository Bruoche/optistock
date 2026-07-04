<?php

namespace Tests\Unit;

use App\Services\Coordinate;
use App\Services\TourSegment;
use App\Services\TravelTimeService;
use App\Services\WorkdayEstimator;
use PHPUnit\Framework\TestCase;

class WorkdayEstimatorTest extends TestCase
{
    private Coordinate $warehouse;

    private Coordinate $a;

    private Coordinate $b;

    private Coordinate $c;

    private Coordinate $d;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = new Coordinate(0.0, 0.0);
        $this->a = new Coordinate(1.0, 1.0);
        $this->b = new Coordinate(2.0, 2.0);
        $this->c = new Coordinate(3.0, 3.0);
        $this->d = new Coordinate(4.0, 4.0);
    }

    public function test_it_sums_warehouse_connections_tour_duration_and_return(): void
    {
        $estimator = new WorkdayEstimator($this->fakeTravel([
            $this->connection($this->warehouse, $this->a) => 10,
            $this->connection($this->b, $this->warehouse) => 20,
        ]));

        $estimate = $estimator->total($this->warehouse, [
            new TourSegment($this->a, $this->b, 100),
        ]);

        $this->assertSame(130, $estimate->projectedDurationS);
        $this->assertFalse($estimate->incomplete);
    }

    public function test_it_chains_between_tours(): void
    {
        $estimator = new WorkdayEstimator($this->fakeTravel([
            $this->connection($this->warehouse, $this->a) => 10, // W → tour1 start
            $this->connection($this->b, $this->c) => 30,         // tour1 end → tour2 start
            $this->connection($this->d, $this->warehouse) => 20, // tour2 end → W
        ]));

        $estimate = $estimator->total($this->warehouse, [
            new TourSegment($this->a, $this->b, 100),
            new TourSegment($this->c, $this->d, 200),
        ]);

        // 10 + 100 + 30 + 200 + 20
        $this->assertSame(360, $estimate->projectedDurationS);
        $this->assertFalse($estimate->incomplete);
    }

    public function test_a_failed_connection_counts_zero_and_flags_incomplete(): void
    {
        $estimator = new WorkdayEstimator($this->fakeTravel([
            $this->connection($this->warehouse, $this->a) => null, // routing failed
            $this->connection($this->b, $this->warehouse) => 20,
        ]));

        $estimate = $estimator->total($this->warehouse, [
            new TourSegment($this->a, $this->b, 100),
        ]);

        // Failed connection contributes 0: 0 + 100 + 20.
        $this->assertSame(120, $estimate->projectedDurationS);
        $this->assertTrue($estimate->incomplete);
    }

    public function test_a_null_tour_duration_counts_zero_and_flags_incomplete(): void
    {
        $estimator = new WorkdayEstimator($this->fakeTravel([
            $this->connection($this->warehouse, $this->a) => 10,
            $this->connection($this->b, $this->warehouse) => 20,
        ]));

        $estimate = $estimator->total($this->warehouse, [
            new TourSegment($this->a, $this->b, null), // unknown own duration
        ]);

        // Null tour duration contributes 0: 10 + 0 + 20.
        $this->assertSame(30, $estimate->projectedDurationS);
        $this->assertTrue($estimate->incomplete);
    }

    public function test_it_is_at_least_the_plain_sum_of_tour_durations(): void
    {
        $estimator = new WorkdayEstimator($this->fakeTravel([
            $this->connection($this->warehouse, $this->a) => 10,
            $this->connection($this->b, $this->warehouse) => 20,
        ]));

        $estimate = $estimator->total($this->warehouse, [
            new TourSegment($this->a, $this->b, 100),
        ]);

        $this->assertGreaterThanOrEqual(100, $estimate->projectedDurationS);
    }

    public function test_driving_seconds_are_the_total_minus_counted_stop_time(): void
    {
        $estimator = new WorkdayEstimator($this->fakeTravel([
            $this->connection($this->warehouse, $this->a) => 10,
            $this->connection($this->b, $this->warehouse) => 20,
        ]));

        // total = 10 + 100 + 20 = 130; the tour holds 40 s of stops → driving = 90.
        $estimate = $estimator->total($this->warehouse, [
            new TourSegment($this->a, $this->b, 100, stopSecondsS: 40),
        ]);

        $this->assertSame(130, $estimate->projectedDurationS);
        $this->assertSame(90, $estimate->drivingDurationS);
        $this->assertLessThanOrEqual($estimate->projectedDurationS, $estimate->drivingDurationS);
    }

    public function test_an_unknown_tour_duration_does_not_subtract_its_stops_from_driving(): void
    {
        $estimator = new WorkdayEstimator($this->fakeTravel([
            $this->connection($this->warehouse, $this->a) => 10,
            $this->connection($this->b, $this->warehouse) => 20,
        ]));

        // Tour duration unknown → counts 0 in the total, so its 40 s of stops are NOT subtracted;
        // total = 10 + 0 + 20 = 30, driving = 30 (never negative).
        $estimate = $estimator->total($this->warehouse, [
            new TourSegment($this->a, $this->b, null, stopSecondsS: 40),
        ]);

        $this->assertSame(30, $estimate->projectedDurationS);
        $this->assertSame(30, $estimate->drivingDurationS);
        $this->assertGreaterThanOrEqual(0, $estimate->drivingDurationS);
    }

    /**
     * @param  array<string, int|null>  $map  connectionKey → duration
     */
    private function fakeTravel(array $map): TravelTimeService
    {
        return new class($map) extends TravelTimeService
        {
            /** @param array<string, int|null> $map */
            public function __construct(private array $map) {}

            public function durationBetween(Coordinate $from, Coordinate $to, ?string $mode = null): ?int
            {
                return $this->map[$from->key().'>'.$to->key()] ?? null;
            }

            public function preload(array $connections, ?string $mode = null): void {}
        };
    }

    private function connection(Coordinate $from, Coordinate $to): string
    {
        return $from->key().'>'.$to->key();
    }
}
