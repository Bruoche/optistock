<?php

namespace Tests\Unit;

use App\Exceptions\TourGeometryException;
use App\Services\Coordinate;
use App\Services\OpenStreetRouteClient;
use App\Services\PolylineDecoder;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;

class OpenStreetRouteClientTest extends TestCase
{
    private const URL = 'https://maps.open-street.com/api/route/';

    private function origin(): Coordinate
    {
        return new Coordinate(48.8566, 2.3522);
    }

    private function destination(): Coordinate
    {
        return new Coordinate(45.7640, 4.8357);
    }

    private function client(HttpFactory $http): OpenStreetRouteClient
    {
        // Precision 5 here so the canonical Google test vector decodes to its known
        // values; production uses precision 6 (verified live — see research.md R1).
        return new OpenStreetRouteClient($http, new PolylineDecoder, self::URL, 'secret-key', 'trucking', 8, 5);
    }

    public function test_it_maps_a_successful_response(): void
    {
        $http = new HttpFactory;
        $http->fake([
            '*' => $http->response([
                'polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
                'total_distance' => 465000,
                'total_time' => 16800,
                'status' => 0,
            ]),
        ]);

        $leg = $this->client($http)->traceLeg($this->origin(), $this->destination());

        $this->assertSame(465000, $leg['distance_m']);
        $this->assertSame(16800, $leg['duration_s']);
        $this->assertSame([
            [38.5, -120.2],
            [40.7, -120.95],
            [43.252, -126.453],
        ], $leg['coordinates']);
    }

    public function test_it_sends_the_expected_query_parameters(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response(['polyline' => '', 'total_distance' => 0, 'total_time' => 0, 'status' => 'OK'])]);

        $this->client($http)->traceLeg($this->origin(), $this->destination());

        $http->assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['origin'] === '48.8566,2.3522'
                && $query['destination'] === '45.764,4.8357'
                && $query['mode'] === 'trucking'
                && $query['key'] === 'secret-key';
        });
    }

    public function test_it_accepts_string_ok_status(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response(['polyline' => '_p~iF~ps|U', 'total_distance' => 1, 'total_time' => 1, 'status' => 'OK'])]);

        $leg = $this->client($http)->traceLeg($this->origin(), $this->destination());

        $this->assertSame([[38.5, -120.2]], $leg['coordinates']);
    }

    public function test_it_throws_invalid_response_on_error_status(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response(['status' => 'WRONG_KEY'])]);

        $this->assertErrorCode('invalid_response', fn () => $this->client($http)->traceLeg($this->origin(), $this->destination()));
    }

    public function test_it_throws_invalid_response_when_polyline_missing(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response(['status' => 0, 'total_distance' => 1, 'total_time' => 1])]);

        $this->assertErrorCode('invalid_response', fn () => $this->client($http)->traceLeg($this->origin(), $this->destination()));
    }

    public function test_it_throws_api_error_on_http_failure(): void
    {
        $http = new HttpFactory;
        $http->fake(['*' => $http->response('', 500)]);

        $this->assertErrorCode('api_error', fn () => $this->client($http)->traceLeg($this->origin(), $this->destination()));
    }

    public function test_it_throws_timeout_on_connection_failure(): void
    {
        $http = new HttpFactory;
        $http->fake(function (): void {
            throw new ConnectionException('Connection timed out');
        });

        $this->assertErrorCode('timeout', fn () => $this->client($http)->traceLeg($this->origin(), $this->destination()));
    }

    private function jsonResponse(array $body, int $status = 200): Response
    {
        return new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($body)));
    }

    public function test_leg_from_response_maps_a_successful_body(): void
    {
        $leg = $this->client(new HttpFactory)->legFromResponse($this->jsonResponse([
            'polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
            'total_distance' => 465000,
            'total_time' => 16800,
            'status' => 0,
        ]));

        $this->assertSame(465000, $leg['distance_m']);
        $this->assertSame(16800, $leg['duration_s']);
        $this->assertSame([
            [38.5, -120.2],
            [40.7, -120.95],
            [43.252, -126.453],
        ], $leg['coordinates']);
    }

    public function test_leg_from_response_keeps_metrics_when_the_polyline_is_missing(): void
    {
        $leg = $this->client(new HttpFactory)->legFromResponse($this->jsonResponse([
            'total_distance' => 1000,
            'total_time' => 120,
            'status' => 'OK',
        ]));

        $this->assertNull($leg['coordinates']);
        $this->assertSame(120, $leg['duration_s']);
        $this->assertSame(1000, $leg['distance_m']);
    }

    public function test_leg_from_response_is_null_on_http_failure(): void
    {
        $this->assertNull($this->client(new HttpFactory)->legFromResponse($this->jsonResponse([], 500)));
    }

    public function test_leg_from_response_is_null_on_error_status(): void
    {
        $this->assertNull($this->client(new HttpFactory)->legFromResponse($this->jsonResponse(['status' => 'WRONG_KEY'])));
    }

    private function assertErrorCode(string $expectedCode, callable $callback): void
    {
        try {
            $callback();
        } catch (TourGeometryException $e) {
            $this->assertSame($expectedCode, $e->errorCode);

            return;
        }

        $this->fail("Expected TourGeometryException with code [{$expectedCode}] was not thrown.");
    }
}
