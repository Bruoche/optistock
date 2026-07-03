<?php

namespace Tests\Unit;

use App\Services\Coordinate;
use App\Services\OpenStreetRouteClient;
use App\Services\PolylineDecoder;
use App\Services\PriorTourLeg;
use App\Services\TravelTimeService;
use App\Services\WorkdayLeg;
use App\Services\WorkdayLegsBuilder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WorkdayLegsBuilderTest extends TestCase
{
    private Coordinate $warehouse;

    private Coordinate $candidateStart;

    private Coordinate $candidateEnd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warehouse = new Coordinate(48.90, 2.30);
        $this->candidateStart = new Coordinate(48.85, 2.35);
        $this->candidateEnd = new Coordinate(48.86, 2.36);
    }

    private function builder(): WorkdayLegsBuilder
    {
        return new WorkdayLegsBuilder(new TravelTimeService(
            http: $this->app->make(HttpFactory::class),
            client: $this->app->make(OpenStreetRouteClient::class),
            poolCap: 5,
        ));
    }

    /** A builder whose client decodes at precision 5, matching the canonical polyline test vector. */
    private function builderWithKnownPrecision(): WorkdayLegsBuilder
    {
        $client = new OpenStreetRouteClient(
            $this->app->make(HttpFactory::class),
            new PolylineDecoder,
            'https://maps.open-street.com/api/route/',
            'secret-key',
            'trucking',
            8,
            5,
        );

        return new WorkdayLegsBuilder(new TravelTimeService(
            http: $this->app->make(HttpFactory::class),
            client: $client,
            poolCap: 5,
        ));
    }

    private function priorTour(array $stops, bool $loop, Coordinate $start, Coordinate $end): PriorTourLeg
    {
        return new PriorTourLeg(
            start: $start,
            end: $end,
            loop: $loop,
            stopCoordinates: $stops,
            durationS: 600,
        );
    }

    public function test_no_prior_tours_yields_the_two_warehouse_connections(): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK', 'total_time' => 60, 'total_distance' => 100])]);

        $legs = $this->builder()->build($this->warehouse, [], $this->candidateStart, $this->candidateEnd, 'driving');

        $this->assertCount(2, $legs);
        $this->assertSame([WorkdayLeg::KIND_CONNECTION, WorkdayLeg::KIND_CONNECTION], array_column(array_map(fn (WorkdayLeg $leg) => $leg->toArray(), $legs), 'kind'));
        $this->assertSame([[48.90, 2.30], [48.85, 2.35]], $legs[0]->path);
        $this->assertSame([[48.86, 2.36], [48.90, 2.30]], $legs[1]->path);
        $this->assertTrue($legs[0]->dotted);
        $this->assertFalse($legs[0]->loop);
    }

    public function test_prior_tours_interleave_solid_tour_legs_in_chain_order(): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK', 'total_time' => 60, 'total_distance' => 100])]);
        $stopA = new Coordinate(48.70, 2.10);
        $stopB = new Coordinate(48.71, 2.11);
        $prior = $this->priorTour([$stopA, $stopB], loop: true, start: $stopA, end: $stopA);

        $legs = $this->builder()->build($this->warehouse, [$prior], $this->candidateStart, $this->candidateEnd, 'driving');

        $this->assertSame(
            [WorkdayLeg::KIND_CONNECTION, WorkdayLeg::KIND_TOUR, WorkdayLeg::KIND_CONNECTION, WorkdayLeg::KIND_CONNECTION],
            array_map(fn (WorkdayLeg $leg): string => $leg->kind, $legs),
        );
        // W → prior start, then prior end → candidate start, then candidate end → W.
        $this->assertSame([[48.90, 2.30], [48.70, 2.10]], $legs[0]->path);
        $this->assertSame([[48.70, 2.10], [48.85, 2.35]], $legs[2]->path);
        $this->assertSame([[48.86, 2.36], [48.90, 2.30]], $legs[3]->path);
        $this->assertFalse($legs[1]->dotted);
        $this->assertNull($legs[1]->geometry);
    }

    public function test_connection_geometry_is_attached_when_the_route_has_a_polyline(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'OK', 'total_time' => 60, 'total_distance' => 100,
            'polyline' => '_p~iF~ps|U_ulLnnqC',
        ])]);

        $legs = $this->builderWithKnownPrecision()->build($this->warehouse, [], $this->candidateStart, $this->candidateEnd, 'trucking');

        $this->assertSame([[38.5, -120.2], [40.7, -120.95]], $legs[0]->geometry);
    }

    public function test_an_unroutable_connection_keeps_a_null_geometry_and_its_straight_path(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        Log::spy();

        $legs = $this->builder()->build($this->warehouse, [], $this->candidateStart, $this->candidateEnd, 'driving');

        $this->assertCount(2, $legs);
        $this->assertNull($legs[0]->geometry);
        $this->assertSame([[48.90, 2.30], [48.85, 2.35]], $legs[0]->path);
    }

    public function test_a_loop_tour_path_is_rotated_to_its_recorded_start(): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK', 'total_time' => 60, 'total_distance' => 100])]);
        $s0 = new Coordinate(48.70, 2.10);
        $s1 = new Coordinate(48.71, 2.11);
        $s2 = new Coordinate(48.72, 2.12);
        $prior = $this->priorTour([$s0, $s1, $s2], loop: true, start: $s1, end: $s1);

        $legs = $this->builder()->build($this->warehouse, [$prior], $this->candidateStart, $this->candidateEnd, 'driving');

        $this->assertSame([[48.71, 2.11], [48.72, 2.12], [48.70, 2.10]], $legs[1]->path);
        $this->assertTrue($legs[1]->loop);
    }

    public function test_a_one_way_tour_entered_at_its_far_endpoint_is_reversed(): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK', 'total_time' => 60, 'total_distance' => 100])]);
        $s0 = new Coordinate(48.70, 2.10);
        $s1 = new Coordinate(48.71, 2.11);
        $s2 = new Coordinate(48.72, 2.12);
        $prior = $this->priorTour([$s0, $s1, $s2], loop: false, start: $s2, end: $s0);

        $legs = $this->builder()->build($this->warehouse, [$prior], $this->candidateStart, $this->candidateEnd, 'driving');

        $this->assertSame([[48.72, 2.12], [48.71, 2.11], [48.70, 2.10]], $legs[1]->path);
        $this->assertFalse($legs[1]->loop);
    }

    public function test_a_one_way_tour_entered_at_its_first_stop_keeps_the_stored_order(): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK', 'total_time' => 60, 'total_distance' => 100])]);
        $s0 = new Coordinate(48.70, 2.10);
        $s1 = new Coordinate(48.71, 2.11);
        $prior = $this->priorTour([$s0, $s1], loop: false, start: $s0, end: $s1);

        $legs = $this->builder()->build($this->warehouse, [$prior], $this->candidateStart, $this->candidateEnd, 'driving');

        $this->assertSame([[48.70, 2.10], [48.71, 2.11]], $legs[1]->path);
    }

    public function test_an_unmatched_start_falls_back_to_the_stored_order_and_warns(): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK', 'total_time' => 60, 'total_distance' => 100])]);
        Log::spy();
        $s0 = new Coordinate(48.70, 2.10);
        $s1 = new Coordinate(48.71, 2.11);
        $drifted = new Coordinate(40.00, 1.00);
        $prior = $this->priorTour([$s0, $s1], loop: true, start: $drifted, end: $drifted);

        $legs = $this->builder()->build($this->warehouse, [$prior], $this->candidateStart, $this->candidateEnd, 'driving');

        $this->assertSame([[48.70, 2.10], [48.71, 2.11]], $legs[1]->path);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_coincident_connection_keeps_its_two_point_path_and_null_geometry(): void
    {
        Http::fake(['*' => Http::response(['status' => 'OK', 'total_time' => 60, 'total_distance' => 100])]);

        $legs = $this->builder()->build($this->warehouse, [], $this->warehouse, $this->warehouse, 'driving');

        $this->assertSame([[48.90, 2.30], [48.90, 2.30]], $legs[0]->path);
        $this->assertNull($legs[0]->geometry);
    }
}
