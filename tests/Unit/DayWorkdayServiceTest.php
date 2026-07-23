<?php

namespace Tests\Unit;

use App\Services\Coordinate;
use App\Services\DayAssignment;
use App\Services\DayWorkdayService;
use App\Services\TravelTimeService;
use App\Services\WorkdayEstimator;
use Mockery;
use PHPUnit\Framework\TestCase;

class DayWorkdayServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function assignment(?int $driven, int $stopSeconds): DayAssignment
    {
        return new DayAssignment(
            tourId: 1,
            sequence: 0,
            loop: true,
            mode: 'driving',
            start: new Coordinate(48.85, 2.35),
            end: new Coordinate(48.86, 2.36),
            stops: [['index' => 1, 'coordinate' => new Coordinate(48.85, 2.35), 'duration_s' => $stopSeconds]],
            drivenDurationS: $driven,
            stopSecondsS: $stopSeconds,
        );
    }

    private function service(TravelTimeService $travelTime): DayWorkdayService
    {
        return new DayWorkdayService($travelTime, new WorkdayEstimator($travelTime));
    }

    public function test_totals_split_driven_stop_and_break_over_a_known_day(): void
    {
        $travelTime = Mockery::mock(TravelTimeService::class);
        $travelTime->shouldReceive('preload');
        $travelTime->shouldReceive('durationBetween')->andReturn(60); // every connection routes to 60 s

        // Two tours: totals 150 (driven 100 + stops 50) and 230 (driven 200 + stops 30).
        $a1 = $this->assignment(driven: 100, stopSeconds: 50);
        $a2 = $this->assignment(driven: 200, stopSeconds: 30);

        $summary = $this->service($travelTime)->summarize(new Coordinate(48.80, 2.30), [$a1, $a2]);

        $this->assertSame('driving', $summary['mode']);
        $this->assertFalse($summary['workday']['incomplete']);
        $this->assertSame(80, $summary['workday']['stop_seconds']); // 50 + 30, exact
        // Known identity holds when nothing is unknown: total = driven + stop + break.
        $w = $summary['workday'];
        $this->assertSame($w['driven_seconds'] + $w['stop_seconds'] + $w['break_seconds'], $w['total_seconds']);
    }

    public function test_an_unknown_tour_duration_flags_the_day_incomplete_and_keeps_stops_exact(): void
    {
        $travelTime = Mockery::mock(TravelTimeService::class);
        $travelTime->shouldReceive('preload');
        $travelTime->shouldReceive('durationBetween')->andReturn(60);

        $known = $this->assignment(driven: 100, stopSeconds: 50);
        $unknown = $this->assignment(driven: null, stopSeconds: 40); // travel unknown → total unknown

        $summary = $this->service($travelTime)->summarize(new Coordinate(48.80, 2.30), [$known, $unknown]);

        $this->assertTrue($summary['workday']['incomplete']);
        $this->assertSame(90, $summary['workday']['stop_seconds']); // 50 + 40, still exact
    }

    public function test_an_empty_day_is_all_zero(): void
    {
        $travelTime = Mockery::mock(TravelTimeService::class);
        $travelTime->shouldReceive('preload');
        // Even an empty day has the warehouse→warehouse return leg (coincident → 0 s).
        $travelTime->shouldReceive('durationBetween')->andReturn(0);

        $summary = $this->service($travelTime)->summarize(new Coordinate(48.80, 2.30), []);

        $this->assertNull($summary['mode']);
        $this->assertSame(0, $summary['workday']['total_seconds']);
        $this->assertFalse($summary['workday']['incomplete']);
    }
}
