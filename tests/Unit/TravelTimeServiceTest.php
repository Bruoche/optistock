<?php

namespace Tests\Unit;

use App\Services\Coordinate;
use App\Services\OpenStreetRouteClient;
use App\Services\PolylineDecoder;
use App\Services\TravelTimeService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TravelTimeServiceTest extends TestCase
{
    private function service(int $poolCap = 5): TravelTimeService
    {
        return new TravelTimeService(
            http: $this->app->make(HttpFactory::class),
            client: $this->app->make(OpenStreetRouteClient::class),
            poolCap: $poolCap,
        );
    }

    /** A service whose client decodes at precision 5, matching the canonical polyline test vector. */
    private function serviceWithKnownPrecision(): TravelTimeService
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

        return new TravelTimeService(
            http: $this->app->make(HttpFactory::class),
            client: $client,
            poolCap: 5,
        );
    }

    private function okResponse(int $seconds): array
    {
        return ['status' => 'OK', 'total_time' => $seconds, 'total_distance' => 1000];
    }

    public function test_a_distinct_connection_is_requested_at_most_once(): void
    {
        Http::fake(['*' => Http::response($this->okResponse(120))]);
        $a = new Coordinate(1.0, 1.0);
        $b = new Coordinate(2.0, 2.0);

        // The same connection listed three times must resolve to a single request.
        $this->service()->preload([[$a, $b], [$a, $b], [$a, $b]], 'trucking');

        Http::assertSentCount(1);
    }

    public function test_it_deduplicates_across_a_larger_set(): void
    {
        Http::fake(['*' => Http::response($this->okResponse(120))]);
        $a = new Coordinate(1.0, 1.0);
        $b = new Coordinate(2.0, 2.0);
        $c = new Coordinate(3.0, 3.0);

        // Distinct connections: a→b, b→c, a→c (a→b repeated).
        $this->service()->preload([[$a, $b], [$b, $c], [$a, $b], [$a, $c]], 'trucking');

        Http::assertSentCount(3);
    }

    public function test_all_connections_resolve_even_when_chunked_below_the_cap(): void
    {
        Http::fake(['*' => Http::response($this->okResponse(200))]);
        $points = [];
        for ($i = 1; $i <= 4; $i++) {
            $points[] = new Coordinate((float) $i, 0.0);
        }
        $connections = [[$points[0], $points[1]], [$points[1], $points[2]], [$points[2], $points[3]], [$points[0], $points[3]]];

        // cap = 2 → the 4 distinct connections are fetched in two capped batches.
        $service = $this->service(poolCap: 2);
        $service->preload($connections, 'trucking');

        Http::assertSentCount(4);
        foreach ($connections as [$from, $to]) {
            $this->assertSame(200, $service->durationBetween($from, $to, 'trucking'));
        }
    }

    public function test_coincident_points_are_zero_with_no_request(): void
    {
        Http::fake(['*' => Http::response($this->okResponse(120))]);
        $a = new Coordinate(1.0, 1.0);

        $this->assertSame(0, $this->service()->durationBetween($a, $a, 'trucking'));
        Http::assertNothingSent();
    }

    public function test_a_failed_connection_is_null_and_logged(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        Log::spy();
        $a = new Coordinate(1.0, 1.0);
        $b = new Coordinate(2.0, 2.0);

        $service = $this->service();
        $service->preload([[$a, $b]], 'trucking');

        $this->assertNull($service->durationBetween($a, $b, 'trucking'));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_connection_error_is_null_and_logged_without_crashing(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        Log::spy();
        $a = new Coordinate(1.0, 1.0);
        $b = new Coordinate(2.0, 2.0);

        $service = $this->service();
        $service->preload([[$a, $b]], 'trucking');

        $this->assertNull($service->durationBetween($a, $b, 'trucking'));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_successful_connection_returns_its_duration(): void
    {
        Http::fake(['*' => Http::response($this->okResponse(345))]);
        $a = new Coordinate(1.0, 1.0);
        $b = new Coordinate(2.0, 2.0);

        $this->assertSame(345, $this->service()->durationBetween($a, $b, 'trucking'));
    }

    public function test_a_connection_with_a_polyline_exposes_its_decoded_geometry(): void
    {
        Http::fake(['*' => Http::response($this->okResponse(345) + ['polyline' => '_p~iF~ps|U_ulLnnqC'])]);
        $a = new Coordinate(1.0, 1.0);
        $b = new Coordinate(2.0, 2.0);

        $service = $this->serviceWithKnownPrecision();
        $service->preload([[$a, $b]], 'trucking');

        $this->assertSame([[38.5, -120.2], [40.7, -120.95]], $service->geometryBetween($a, $b, 'trucking'));
        $this->assertSame(345, $service->durationBetween($a, $b, 'trucking'));
        Http::assertSentCount(1);
    }

    public function test_a_connection_without_a_polyline_keeps_its_duration_and_null_geometry(): void
    {
        Http::fake(['*' => Http::response($this->okResponse(345))]);
        $a = new Coordinate(1.0, 1.0);
        $b = new Coordinate(2.0, 2.0);

        $service = $this->service();
        $service->preload([[$a, $b]], 'trucking');

        $this->assertNull($service->geometryBetween($a, $b, 'trucking'));
        $this->assertSame(345, $service->durationBetween($a, $b, 'trucking'));
    }

    public function test_a_failed_connection_has_null_geometry(): void
    {
        Http::fake(['*' => Http::response('', 500)]);
        Log::spy();
        $a = new Coordinate(1.0, 1.0);
        $b = new Coordinate(2.0, 2.0);

        $this->assertNull($this->service()->geometryBetween($a, $b, 'trucking'));
    }

    public function test_coincident_points_have_null_geometry_with_no_request(): void
    {
        Http::fake(['*' => Http::response($this->okResponse(120))]);
        $a = new Coordinate(1.0, 1.0);

        $this->assertNull($this->service()->geometryBetween($a, $a, 'trucking'));
        Http::assertNothingSent();
    }
}
