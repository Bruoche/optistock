<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/** Road travel duration and geometry between points, de-duplicated and fetched in capped concurrent batches. */
class TravelTimeService
{
    /** @var array<string, array{duration_s: int|null, coordinates: array<int, array{0: float, 1: float}>|null}> connectionKey → leg (null duration = unroutable) */
    private array $legByConnection = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly OpenStreetRouteClient $client,
        private readonly int $poolCap,
    ) {}

    /**
     * Fetch the distinct, not-yet-fetched connections into the leg map (capped concurrent batches).
     *
     * @param  array<int, array{0: Coordinate, 1: Coordinate}>  $connections
     */
    public function preload(array $connections, ?string $mode = null): void
    {
        $connectionsToFetch = [];
        foreach ($connections as [$from, $to]) {
            $key = $this->connectionKey($from, $to, $mode);
            if (array_key_exists($key, $this->legByConnection) || isset($connectionsToFetch[$key])) {
                continue;
            }
            if ($from->isSameAs($to)) {
                $this->legByConnection[$key] = ['duration_s' => 0, 'coordinates' => null];

                continue;
            }
            $connectionsToFetch[$key] = [$from, $to];
        }

        foreach (array_chunk($connectionsToFetch, max(1, $this->poolCap), true) as $batch) {
            $this->fetchBatch($batch, $mode);
        }
    }

    /** Road seconds between two points (0 for coincident, null when unroutable). */
    public function durationBetween(Coordinate $from, Coordinate $to, ?string $mode = null): ?int
    {
        return $this->legBetween($from, $to, $mode)['duration_s'];
    }

    /**
     * Decoded road coordinates between two points (null when unroutable or coincident —
     * nothing to draw).
     *
     * @return array<int, array{0: float, 1: float}>|null
     */
    public function geometryBetween(Coordinate $from, Coordinate $to, ?string $mode = null): ?array
    {
        return $this->legBetween($from, $to, $mode)['coordinates'];
    }

    /**
     * @return array{duration_s: int|null, coordinates: array<int, array{0: float, 1: float}>|null}
     */
    private function legBetween(Coordinate $from, Coordinate $to, ?string $mode): array
    {
        $key = $this->connectionKey($from, $to, $mode);
        if (! array_key_exists($key, $this->legByConnection)) {
            $this->preload([[$from, $to]], $mode);
        }

        return $this->legByConnection[$key];
    }

    /**
     * @param  array<string, array{0: Coordinate, 1: Coordinate}>  $batch
     */
    private function fetchBatch(array $batch, ?string $mode): void
    {
        $responses = $this->http->pool(function (Pool $pool) use ($batch, $mode): array {
            $requests = [];
            foreach ($batch as $key => [$from, $to]) {
                $requests[] = $pool->as($key)
                    ->timeout($this->client->timeout())
                    ->connectTimeout($this->client->connectTimeout())
                    ->get($this->client->baseUrl(), $this->client->queryParams($from, $to, $mode));
            }

            return $requests;
        });

        foreach ($batch as $key => [$from, $to]) {
            // The pool yields a ConnectionException instead of a Response when a
            // request cannot connect or times out — same outcome: leg unknown.
            $response = $responses[$key];
            $leg = $response instanceof Response
                ? $this->client->legFromResponse($response)
                : null;

            if ($leg === null) {
                Log::warning('Inter-tour connection could not be routed', [
                    'origin' => $from->toQueryValue(),
                    'destination' => $to->toQueryValue(),
                    'mode' => $mode,
                    'error' => $response instanceof Response
                        ? "HTTP {$response->status()}"
                        : $response->getMessage(),
                ]);
            }
            $this->legByConnection[$key] = [
                'duration_s' => $leg['duration_s'] ?? null,
                'coordinates' => $leg['coordinates'] ?? null,
            ];
        }
    }

    private function connectionKey(Coordinate $from, Coordinate $to, ?string $mode): string
    {
        return $from->key().'>'.$to->key().'@'.($mode ?? '');
    }
}
