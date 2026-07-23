<?php

namespace Tests\Unit;

use App\Models\Driver;
use App\Models\Stop;
use App\Models\Tour;
use App\Models\Warehouse;
use App\Services\TourOrderService;
use App\Services\TourStartSelector;
use App\Services\TravelTimeService;
use App\Services\UnroutableConnectionException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** Feature 025 (US4): the reorder recompute — nearest-entry chaining, block detection, force fallback. */
class TourOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function driverWithTours(): array
    {
        $warehouse = Warehouse::factory()->create(['latitude' => 48.80, 'longitude' => 2.30]);
        $driver = Driver::factory()->create(['warehouse_id' => $warehouse->id]);

        $a = Tour::factory()->withMode('driving')->create(['loop' => false]);
        Stop::factory()->for($a)->create(['latitude' => 48.85, 'longitude' => 2.35, 'position' => 0]);
        Stop::factory()->for($a)->create(['latitude' => 48.86, 'longitude' => 2.36, 'position' => 1]);

        $b = Tour::factory()->withMode('driving')->create(['loop' => false]);
        Stop::factory()->for($b)->create(['latitude' => 48.90, 'longitude' => 2.40, 'position' => 0]);
        Stop::factory()->for($b)->create(['latitude' => 48.91, 'longitude' => 2.41, 'position' => 1]);

        return [$driver, $a, $b];
    }

    private function service(TravelTimeService $travelTime): TourOrderService
    {
        return new TourOrderService($travelTime, new TourStartSelector($travelTime));
    }

    public function test_a_routable_reorder_produces_a_row_per_tour_in_order(): void
    {
        [$driver, $a, $b] = $this->driverWithTours();

        $travelTime = Mockery::mock(TravelTimeService::class);
        $travelTime->shouldReceive('preload');
        $travelTime->shouldReceive('durationBetween')->andReturn(120); // every connection routes

        $rows = $this->service($travelTime)->reorder($driver, [$b->id, $a->id], false);

        $this->assertCount(2, $rows);
        $this->assertSame($b->id, $rows[0]['tour_id']);
        $this->assertSame(0, $rows[0]['sequence']);
        $this->assertSame($a->id, $rows[1]['tour_id']);
        $this->assertSame(1, $rows[1]['sequence']);
    }

    public function test_an_unroutable_connection_blocks_the_normal_reorder(): void
    {
        [$driver, $a, $b] = $this->driverWithTours();

        $travelTime = Mockery::mock(TravelTimeService::class);
        $travelTime->shouldReceive('preload');
        $travelTime->shouldReceive('durationBetween')->andReturn(null); // routing down

        $this->expectException(UnroutableConnectionException::class);
        $this->service($travelTime)->reorder($driver, [$a->id, $b->id], false);
    }

    public function test_force_uses_no_routing_and_still_orders_the_day(): void
    {
        [$driver, $a, $b] = $this->driverWithTours();

        $travelTime = Mockery::mock(TravelTimeService::class);
        // Force must not touch the routing service at all.
        $travelTime->shouldNotReceive('preload');
        $travelTime->shouldNotReceive('durationBetween');

        $rows = $this->service($travelTime)->reorder($driver, [$b->id, $a->id], true);

        $this->assertSame([$b->id, $a->id], array_column($rows, 'tour_id'));
        $this->assertSame([0, 1], array_column($rows, 'sequence'));
        // Lowest-position entry of tour B (position 0 → 48.90, 2.40).
        $this->assertSame(48.90, $rows[0]['start_latitude']);
    }
}
