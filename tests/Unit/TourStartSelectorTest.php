<?php

namespace Tests\Unit;

use App\Models\Stop;
use App\Models\Tour;
use App\Services\Coordinate;
use App\Services\TourStartSelector;
use App\Services\TravelTimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TourStartSelectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_way_tour_picks_the_nearer_endpoint_and_the_far_one_becomes_the_end(): void
    {
        $incoming = new Coordinate(0.0, 0.0);
        $tour = Tour::factory()->create(['loop' => false]);
        $near = Stop::factory()->for($tour)->create(['latitude' => 0.0, 'longitude' => 2.0, 'position' => 0]);
        $far = Stop::factory()->for($tour)->create(['latitude' => 0.0, 'longitude' => 1.0, 'position' => 3]);

        $selector = new TourStartSelector($this->fakeTravel([
            $this->leg($incoming, $near->coordinate) => 90,
            $this->leg($incoming, $far->coordinate) => 20,
        ]));

        $start = $selector->select($incoming, $tour, 'trucking');

        $this->assertSame(3, $start->startIndex);
        $this->assertTrue($start->start->isSameAs($far->coordinate));
        $this->assertTrue($start->end->isSameAs($near->coordinate));
    }

    public function test_looping_tour_picks_the_closest_of_all_stops_and_end_equals_start(): void
    {
        $incoming = new Coordinate(0.0, 0.0);
        $tour = Tour::factory()->create(['loop' => true]);
        $s0 = Stop::factory()->for($tour)->create(['latitude' => 1.0, 'longitude' => 0.0, 'position' => 0]);
        $s1 = Stop::factory()->for($tour)->create(['latitude' => 2.0, 'longitude' => 0.0, 'position' => 1]);
        $s2 = Stop::factory()->for($tour)->create(['latitude' => 3.0, 'longitude' => 0.0, 'position' => 2]);

        $selector = new TourStartSelector($this->fakeTravel([
            $this->leg($incoming, $s0->coordinate) => 80,
            $this->leg($incoming, $s1->coordinate) => 15,
            $this->leg($incoming, $s2->coordinate) => 40,
        ]));

        $start = $selector->select($incoming, $tour, 'trucking');

        $this->assertSame(1, $start->startIndex);
        $this->assertTrue($start->end->isSameAs($s1->coordinate));
    }

    public function test_uses_the_incoming_point_supplied_by_the_caller(): void
    {
        $tour = Tour::factory()->create(['loop' => true]);
        $s0 = Stop::factory()->for($tour)->create(['latitude' => 1.0, 'longitude' => 0.0, 'position' => 0]);
        $s1 = Stop::factory()->for($tour)->create(['latitude' => 2.0, 'longitude' => 0.0, 'position' => 1]);

        // A different incoming point yields a different nearest start — the selector
        // reaches for nothing itself; the caller decides the incoming point.
        $priorEnd = new Coordinate(9.0, 9.0);
        $selector = new TourStartSelector($this->fakeTravel([
            $this->leg($priorEnd, $s0->coordinate) => 5,
            $this->leg($priorEnd, $s1->coordinate) => 50,
        ]));

        $this->assertSame(0, $selector->select($priorEnd, $tour, 'trucking')->startIndex);
    }

    public function test_equal_travel_times_break_deterministically_to_the_lowest_position(): void
    {
        $incoming = new Coordinate(0.0, 0.0);
        $tour = Tour::factory()->create(['loop' => true]);
        $s0 = Stop::factory()->for($tour)->create(['latitude' => 1.0, 'longitude' => 0.0, 'position' => 0]);
        $s1 = Stop::factory()->for($tour)->create(['latitude' => 2.0, 'longitude' => 0.0, 'position' => 1]);

        $selector = new TourStartSelector($this->fakeTravel([
            $this->leg($incoming, $s0->coordinate) => 30,
            $this->leg($incoming, $s1->coordinate) => 30,
        ]));

        $this->assertSame(0, $selector->select($incoming, $tour, 'trucking')->startIndex);
    }

    public function test_all_unknown_legs_fall_back_to_the_lowest_position(): void
    {
        $incoming = new Coordinate(0.0, 0.0);
        $tour = Tour::factory()->create(['loop' => false]);
        Stop::factory()->for($tour)->create(['latitude' => 1.0, 'longitude' => 0.0, 'position' => 0]);
        Stop::factory()->for($tour)->create(['latitude' => 2.0, 'longitude' => 0.0, 'position' => 5]);

        // No legs mapped → every candidate is unknown.
        $selector = new TourStartSelector($this->fakeTravel([]));

        $this->assertSame(0, $selector->select($incoming, $tour, 'trucking')->startIndex);
    }

    /**
     * @param  array<string, int|null>  $map
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

            public function prime(array $legs, ?string $mode = null): void {}
        };
    }

    private function leg(Coordinate $from, Coordinate $to): string
    {
        return $from->key().'>'.$to->key();
    }
}
