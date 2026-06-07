<?php

namespace Tests\Unit;

use App\Exceptions\TourOptimizationException;
use App\Services\OpenStreetTspClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\TestCase;

class OpenStreetTspClientTest extends TestCase
{
    private const URL = 'https://maps.open-street.com/api/tsp/';

    /**
     * Three points — the API minimum. Two points are short-circuited and never
     * reach the API (see test_it_short_circuits_a_two_point_tour...).
     *
     * @return array<int, array{lat: float, lng: float}>
     */
    private function coordinates(): array
    {
        return [
            ['lat' => 49.89988, 'lng' => 2.30028],
            ['lat' => 48.78300, 'lng' => 2.33316],
            ['lat' => 43.29650, 'lng' => 5.36980],
        ];
    }

    public function test_it_maps_a_successful_response(): void
    {
        $http = new HttpFactory;
        // API echoes only the visit order as input indices (here: visit point 2
        // first, then 0, then 1) plus aggregate totals — never coordinates.
        $http->fake([
            '*' => $http->response([
                'DIMENSION' => 3,
                'TOUR' => 'closed',
                'OPTIMIZATION' => [2, 0, 1],
                'STEPS_DISTANCES' => ['TOTAL' => 450000, '0' => 250000, '1' => 200000],
                'STEPS_DURATIONS' => ['TOTAL' => 18000, '0' => 10000, '1' => 8000],
            ]),
        ]);

        $client = new OpenStreetTspClient($http, self::URL, 'secret-key', 8, 0);
        $result = $client->optimize($this->coordinates());

        $this->assertSame(450000, $result['total_distance_m']);
        $this->assertSame(18000, $result['total_duration_s']);
        $this->assertCount(3, $result['ordered_stops']);
        // Index 2 resolved to the third input coordinate, placed first (order 0).
        $this->assertSame(['lat' => 43.29650, 'lng' => 5.36980, 'order' => 0], $result['ordered_stops'][0]);
        $this->assertSame(['lat' => 49.89988, 'lng' => 2.30028, 'order' => 1], $result['ordered_stops'][1]);
        $this->assertSame(['lat' => 48.78300, 'lng' => 2.33316, 'order' => 2], $result['ordered_stops'][2]);
    }

    public function test_it_short_circuits_a_two_point_tour_without_calling_the_api(): void
    {
        $http = new HttpFactory;
        $http->fake();

        $client = new OpenStreetTspClient($http, self::URL, 'secret-key', 8, 0);
        $result = $client->optimize([
            ['lat' => 49.89988, 'lng' => 2.30028],
            ['lat' => 48.78300, 'lng' => 2.33316],
        ]);

        // Returned in input order; metrics null (no routing call made).
        $this->assertNull($result['total_distance_m']);
        $this->assertNull($result['total_duration_s']);
        $this->assertSame([
            ['lat' => 49.89988, 'lng' => 2.30028, 'order' => 0],
            ['lat' => 48.78300, 'lng' => 2.33316, 'order' => 1],
        ], $result['ordered_stops']);
        $http->assertNothingSent();
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

            return $query['mode'] === 'trucking'
                && $query['unit'] === 'm'
                && $query['tour'] === 'closed'
                && $query['nb'] === '3'
                && $query['key'] === 'secret-key'
                && $query['pts'] === '49.89988,2.30028|48.783,2.33316|43.2965,5.3698';
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

    public function test_it_throws_invalid_response_on_unknown_point_index(): void
    {
        $http = new HttpFactory;
        // Index 5 has no matching input coordinate (only 3 sent) — must not be
        // silently dropped or fatal; the client maps it to invalid_response.
        $http->fake(['*' => $http->response([
            'OPTIMIZATION' => [0, 5],
            'STEPS_DISTANCES' => ['TOTAL' => 0],
            'STEPS_DURATIONS' => ['TOTAL' => 0],
        ])]);

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
     * Assert the callback throws a TourOptimizationException with the given code,
     * returning the exception for further assertions.
     */
    private function assertErrorCode(string $expectedCode, callable $callback): TourOptimizationException
    {
        try {
            $callback();
        } catch (TourOptimizationException $e) {
            $this->assertSame($expectedCode, $e->errorCode);

            return $e;
        }

        $this->fail("Expected TourOptimizationException with code [{$expectedCode}] was not thrown.");
    }
}
