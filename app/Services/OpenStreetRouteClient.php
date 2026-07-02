<?php

namespace App\Services;

use App\Exceptions\TourGeometryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Thin client for the OpenStreet /route endpoint — the road path of ONE leg.
 *
 * Request shape (GET):
 *   {url}?origin=lat,lng&destination=lat,lng&mode=trucking&key=...
 *
 * Unlike the TSP API this call is fast, so it runs synchronously with a short
 * timeout. The response carries a Google encoded polyline plus aggregate metrics;
 * we decode the polyline to coordinates and map metrics to our internal shape.
 * Every failure becomes a typed {@see TourGeometryException} so the caller can
 * fall back to a straight segment for the leg.
 */
class OpenStreetRouteClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly PolylineDecoder $decoder,
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly string $mode = 'trucking',
        private readonly int $timeout = 15,
        private readonly int $precision = 6,
    ) {}

    /**
     * Fetch the road geometry + metrics for a single origin → destination leg.
     *
     * @param  string|null  $mode  Travel mode override; defaults to the configured mode.
     * @return array{
     *     coordinates: array<int, array{0: float, 1: float}>,
     *     distance_m: int,
     *     duration_s: int
     * }
     *
     * @throws TourGeometryException
     */
    public function traceLeg(Coordinate $origin, Coordinate $destination, ?string $mode = null): array
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->get($this->baseUrl, $this->queryParams($origin, $destination, $mode));
        } catch (ConnectionException $e) {
            throw TourGeometryException::timeout($this->timeout, $e);
        }
        if ($response->failed()) {
            throw TourGeometryException::apiError("OpenStreet /route returned HTTP {$response->status()}.");
        }

        return $this->mapToLeg($response->json());
    }

    /**
     * The /route endpoint + read timeout, exposed so a batched (pooled) caller can build
     * requests identical to {@see traceLeg} without duplicating this configuration
     * (feature 013 — {@see TravelTimeService}).
     */
    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    /**
     * The query string for one origin → destination leg — the single source shared by
     * the synchronous {@see traceLeg} and the pooled travel-time path.
     *
     * @return array{origin: string, destination: string, mode: string, key: string|null}
     */
    public function queryParams(Coordinate $origin, Coordinate $destination, ?string $mode = null): array
    {
        return [
            'origin' => $origin->toQueryValue(),
            'destination' => $destination->toQueryValue(),
            'mode' => $mode ?? $this->mode,
            'key' => $this->apiKey,
        ];
    }

    /**
     * The road duration (seconds) of a single leg from a raw /route response, or **null**
     * when the leg could not be determined (HTTP failure or an error status). Unlike
     * {@see traceLeg} this never throws — the pooled path maps each response to a nullable
     * duration and logs the misses itself (feature 013).
     */
    public function durationFromResponse(Response $response): ?int
    {
        if ($response->failed()) {
            return null;
        }

        $body = $response->json();
        if (! is_array($body) || ! $this->isSuccess($body['status'] ?? null)) {
            return null;
        }

        return (int) ($body['total_time'] ?? 0);
    }

    /**
     * @return array{
     *     coordinates: array<int, array{0: float, 1: float}>,
     *     distance_m: int,
     *     duration_s: int
     * }
     */
    private function mapToLeg(mixed $body): array
    {
        if (! is_array($body) || ! $this->isSuccess($body['status'] ?? null)) {
            $status = is_array($body) ? ($body['status'] ?? 'missing') : 'non-object';
            throw TourGeometryException::invalidResponse("OpenStreet /route returned status [{$status}].");
        }
        if (! isset($body['polyline']) || ! is_string($body['polyline'])) {
            throw TourGeometryException::invalidResponse('OpenStreet /route response is missing the polyline.');
        }

        return [
            'coordinates' => $this->decoder->decode($body['polyline'], $this->precision),
            'distance_m' => (int) ($body['total_distance'] ?? 0),
            'duration_s' => (int) ($body['total_time'] ?? 0),
        ];
    }

    /**
     * Success is signalled by status `0` or `"OK"`; any other value is a failure
     * (`SYNTAX_ERROR`, `LIMIT_REACHED`, `WRONG_KEY`, `REQUEST_DENIED`, …).
     */
    private function isSuccess(mixed $status): bool
    {
        return $status === 0 || $status === '0' || $status === 'OK';
    }
}
