<?php

namespace Tests\Unit;

use App\Services\Coordinate;
use App\Services\DayAssignment;
use App\Services\DayLegsBuilder;
use App\Services\TravelTimeService;
use Mockery;
use PHPUnit\Framework\TestCase;

class DayLegsBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function assignment(int $id, int $sequence, Coordinate $start, Coordinate $end): DayAssignment
    {
        return new DayAssignment(
            tourId: $id,
            sequence: $sequence,
            loop: true,
            mode: 'driving',
            start: $start,
            end: $end,
            stops: [
                ['index' => 1, 'coordinate' => $start, 'duration_s' => 60],
                ['index' => 2, 'coordinate' => $end, 'duration_s' => 60],
            ],
            drivenDurationS: 300,
            stopSecondsS: 120,
        );
    }

    public function test_it_builds_neutral_connection_tour_connection_legs_in_chain_order(): void
    {
        $travelTime = Mockery::mock(TravelTimeService::class);
        $travelTime->shouldReceive('geometryBetween')->andReturn(null); // straight fallback

        $warehouse = new Coordinate(48.80, 2.30);
        $t1 = $this->assignment(1, 0, new Coordinate(48.85, 2.35), new Coordinate(48.86, 2.36));
        $t2 = $this->assignment(2, 1, new Coordinate(48.90, 2.40), new Coordinate(48.91, 2.41));

        $legs = (new DayLegsBuilder($travelTime))->build($warehouse, [$t1, $t2], 'driving');

        $this->assertSame(
            ['connection', 'tour', 'connection', 'tour', 'connection'],
            array_map(fn ($leg) => $leg->kind, $legs),
        );
        // All neutral — the day view has no server-chosen highlight.
        foreach ($legs as $leg) {
            $this->assertFalse($leg->highlight);
        }
        // Tour legs are solid, connections dotted.
        $this->assertFalse($legs[1]->dotted);
        $this->assertTrue($legs[0]->dotted);
        // First tour leg's path is its stop coordinates in order.
        $this->assertSame([[48.85, 2.35], [48.86, 2.36]], $legs[1]->path);
    }

    public function test_an_empty_day_has_no_legs(): void
    {
        $travelTime = Mockery::mock(TravelTimeService::class);

        $legs = (new DayLegsBuilder($travelTime))->build(new Coordinate(48.80, 2.30), [], 'driving');

        $this->assertSame([], $legs);
    }
}
