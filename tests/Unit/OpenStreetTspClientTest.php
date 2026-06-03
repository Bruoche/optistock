<?php

namespace Tests\Unit;

use App\Exceptions\RouteOptimizationException;
use App\Services\OpenStreetTspClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;

class OpenStreetTspClientTest extends TestCase
{
    private const URL = 'https://maps.open-street.com/api/tsp/';

    /**
     * @return array<int, array{lat: float, lng: float}>
     */
    private function coordinates(): array
    {
        return [
            ['lat' => 49.89988, 'lng' => 2.30028],
            ['lat' => 48.78300, 'lng' => 2.33316],
        ];
    }

    public function test_it_maps_a_successful_response(): void
    {
        $http = new HttpFactory;
        // API echoes only the visit order as input indices (here: visit point 1
        // first, then point 0) plus aggregate totals — never coordinates.
        $http->fake([
            '*' => $http->response([
                'DIMENSION' => 2,
                'TOUR' => 'closed',
                'OPTIMIZATION' => [1, 0],
                'STEPS_DISTANCES' => ['TOTAL' => 450000, '0' => 250000, '1' => 200000],
                'STEPS_DURATIONS' => ['TOTAL' => 18000, '0' => 10000, '1' => 8000],
            ]),
        ]);

        $client = new OpenStreetTspClient($http, self::URL, 'secret-key', 8, 0);
        $result = $client->optimize($this->coordinates());

        $this->assertSame(450000, $result['total_distance_m']);
        $this->assertSame(18000, $result['total_duration_s']);
        $this->assertCount(2, $result['ordered_stops']);
        // Index 1 resolved to the second input coordinate, placed first (order 0).
        $this->assertSame(['lat' => 48.78300, 'lng' => 2.33316, 'order' => 0], $result['ordered_stops'][0]);
        $this->assertSame(['lat' => 49.89988, 'lng' => 2.30028, 'order' => 1], $result['ordered_stops'][1]);
    }

    public function test_it_sends_the_expected_query_parameters(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*' => $http->response(['OPTIMIZATION' => [], 'STEPS_DISTANCES' => ['TOTAL' => 0], 'STEPS_DURATIONS' => ['TOTAL' => 0]]),
        ]);

        $client = new OpenStreetTspClient($http, self::URL, 'secret-key', 8, 0);
        $client->optimize($this->coordinates());

        $http->assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['mode'] === 'driving'
                && $query['unit'] === 'm'
                && $query['tour'] === 'closed'
                && $query['nb'] === '2'
                && $query['key'] === 'secret-key'
                && $query['pts'] === '49.89988,2.30028|48.783,2.33316';
        });
    }

    public function test_it_throws_api_error_on_http_failure(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 500)]);

        $client = new OpenStreetTspClient($http, self::URL, 'secret-key', 8, 0);

        $this->assertErrorCode('api_error', fn () => $client->optimize($this->coordinates()));
    }

    public function test_it_throws_invalid_response_on_error_status(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response(['message' => 'No route found'])]);

        $client = new OpenStreetTspClient($http, self::URL, 'secret-key', 8, 0);

        $exception = $this->assertErrorCode('invalid_response', fn () => $client->optimize($this->coordinates()));
        $this->assertSame('No route found', $exception->getMessage());
    }

    public function test_it_throws_invalid_response_when_optimization_missing(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response(['DIMENSION' => 2, 'TOUR' => 'closed'])]);

        $client = new OpenStreetTspClient($http, self::URL, 'secret-key', 8, 0);

        $this->assertErrorCode('invalid_response', fn () => $client->optimize($this->coordinates()));
    }

    public function test_it_throws_timeout_on_connection_failure(): void
    {
        $http = new HttpFactory;
        $http->fake(function (): void {
            throw new ConnectionException('Connection timed out');
        });

        $client = new OpenStreetTspClient($http, self::URL, 'secret-key', 8, 0);

        $this->assertErrorCode('timeout', fn () => $client->optimize($this->coordinates()));
    }

    /**
     * Assert the callback throws a RouteOptimizationException with the given code,
     * returning the exception for further assertions.
     */
    private function assertErrorCode(string $expectedCode, callable $callback): RouteOptimizationException
    {
        try {
            $callback();
        } catch (RouteOptimizationException $e) {
            $this->assertSame($expectedCode, $e->errorCode);

            return $e;
        }

        $this->fail("Expected RouteOptimizationException with code [{$expectedCode}] was not thrown.");
    }
}
