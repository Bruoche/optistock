<?php

namespace App\Services;

use App\Exceptions\TourOptimizationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Thin client for the OpenStreet TSP route-optimization API.
 *
 * Request shape (GET):
 *   {url}?pts=lat,lng|lat,lng|...&nb=N&mode=driving&unit=m&tour=closed&key=...
 *
 * MUST only be called from a background job — the upstream API can take
 * several minutes for large point sets. We therefore use a generous *read*
 * timeout (slow compute is expected) but a short *connect* timeout (a dead
 * host must fail fast, never hang a worker), with bounded exponential-backoff
 * retries, and map every failure to a typed {@see TourOptimizationException}.
 */
class OpenStreetTspClient
{
    /**
     * The TSP API rejects fewer than 3 points with `{"status":"NB_IS_INCONSISTENT"}`.
     * A 2-point closed tour is already optimal (A→B→A cannot be reordered), so we
     * short-circuit it instead of calling the API.
     */
    private const MIN_TSP_POINTS = 3;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 600,
        private readonly int $retries = 1,
        private readonly int $connectTimeout = 15,
        private readonly string $mode = 'trucking',
    ) {}

    /**
     * @param  array<int, array{lat: float, lng: float}>  $coordinates
     * @return array{
     *     ordered_stops: array<int, array{lat: float, lng: float, order: int}>,
     *     total_distance_m: int|null,
     *     total_duration_s: int|null
     * }
     *
     * @throws TourOptimizationException
     */
    public function optimize(array $coordinates): array
    {
        // A 2-point tour is trivially optimal + the API can't process it. 
		// Return it as-is.
		// Distance/duration require a routing call we don't make here, so they are left null until the OpenStreet /route/ endpoint is wired in
        if (count($coordinates) < self::MIN_TSP_POINTS) {
            return $this->trivialTour($coordinates);
        }

        $points = implode('|', array_map(
            static fn (array $c): string => $c['lat'].','.$c['lng'],
            $coordinates,
        ));

        $response = $this->send($points, count($coordinates));

        if ($response->failed()) {
            throw TourOptimizationException::apiError("OpenStreet API returned HTTP {$response->status()}.");
        }

        return $this->mapToTour($response->json(), $coordinates);
    }

    /**
     * Build an already-optimal tour for fewer than {@see MIN_TSP_POINTS} stops,
     * preserving the caller's order. Metrics are null (no routing call made).
     *
     * @param  array<int, array{lat: float, lng: float}>  $coordinates
     * @return array{
     *     ordered_stops: array<int, array{lat: float, lng: float, order: int}>,
     *     total_distance_m: null,
     *     total_duration_s: null
     * }
     */
    private function trivialTour(array $coordinates): array
    {
        $orderedStops = [];
        foreach (array_values($coordinates) as $order => $c) {
            $orderedStops[] = [
                'lat' => (float) $c['lat'],
                'lng' => (float) $c['lng'],
                'order' => $order,
            ];
        }

        return [
            'ordered_stops' => $orderedStops,
            'total_distance_m' => null,
            'total_duration_s' => null,
        ];
    }

    private function send(string $points, int $count): Response
    {
        try {
            return $this->http
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->retry(
                    $this->retries + 1,
                    // Exponential backoff: 1s before retry 1, 2s before retry 2, ...
                    sleepMilliseconds: static fn (int $attempt): int => $attempt * 1000,
                    throw: false,
                )
                ->get($this->baseUrl, [
                    'pts' => $points,
                    'nb' => $count,
                    'mode' => $this->mode,
                    'unit' => 'm',
                    'tour' => 'closed',
                    'key' => $this->apiKey,
                ]);
        } catch (ConnectionException $e) {
            throw TourOptimizationException::timeout(
                "OpenStreet API did not respond within {$this->timeout}s: {$e->getMessage()}",
                $e,
            );
        }
    }

    /**
     * Map the OpenStreet TSP payload to our internal shape.
     *
     * The API returns visit order as a list of *input indices* in
     * `OPTIMIZATION` (it echoes no coordinates), plus aggregate metrics under
     * `STEPS_DISTANCES.TOTAL` (meters) and `STEPS_DURATIONS.TOTAL` (seconds).
     * We resolve each index back to the original coordinate the caller sent.
     *
     * @param  array<int, array{lat: float, lng: float}>  $coordinates
     * @return array{
     *     ordered_stops: array<int, array{lat: float, lng: float, order: int}>,
     *     total_distance_m: int,
     *     total_duration_s: int
     * }
     */
    private function mapToTour(mixed $body, array $coordinates): array
    {
        if (! is_array($body) || ! isset($body['OPTIMIZATION']) || ! is_array($body['OPTIMIZATION'])) {
            $message = is_array($body) && isset($body['message'])
                ? (string) $body['message']
                : 'OpenStreet API returned an unexpected payload.';

            throw TourOptimizationException::invalidResponse($message);
        }

        $orderedStops = [];
        foreach (array_values($body['OPTIMIZATION']) as $order => $pointIndex) {
            $pointIndex = (int) $pointIndex;

            if (! isset($coordinates[$pointIndex])) {
                throw TourOptimizationException::invalidResponse(
                    "OpenStreet API referenced unknown point index {$pointIndex}.",
                );
            }

            $orderedStops[] = [
                'lat' => (float) $coordinates[$pointIndex]['lat'],
                'lng' => (float) $coordinates[$pointIndex]['lng'],
                'order' => $order,
            ];
        }

        return [
            'ordered_stops' => $orderedStops,
            'total_distance_m' => (int) ($body['STEPS_DISTANCES']['TOTAL'] ?? 0),
            'total_duration_s' => (int) ($body['STEPS_DURATIONS']['TOTAL'] ?? 0),
        ];
    }
}
