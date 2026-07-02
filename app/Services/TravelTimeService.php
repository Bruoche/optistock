<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/** Road travel duration between points, de-duplicated and fetched in capped concurrent batches. */
class TravelTimeService
{
    /** @var array<string, int|null> connectionKey → duration seconds (null = unroutable) */
    private array $durationByConnection = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly OpenStreetRouteClient $client,
        private readonly int $poolCap,
    ) {}

    /**
     * Fetch the distinct, not-yet-fetched connections into the duration map (capped concurrent batches).
     *
     * @param  array<int, array{0: Coordinate, 1: Coordinate}>  $connections
     */
    public function preload(array $connections, ?string $mode = null): void
    {
        $connectionsToFetch = [];
        foreach ($connections as [$from, $to]) {
            $key = $this->connectionKey($from, $to, $mode);
            if (array_key_exists($key, $this->durationByConnection) || isset($connectionsToFetch[$key])) {
                continue;
            }
            if ($from->isSameAs($to)) {
                $this->durationByConnection[$key] = 0;

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
        if ($from->isSameAs($to)) {
            return 0;
        }

        $key = $this->connectionKey($from, $to, $mode);
        if (! array_key_exists($key, $this->durationByConnection)) {
            $this->preload([[$from, $to]], $mode);
        }

        return $this->durationByConnection[$key];
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
                    ->get($this->client->baseUrl(), $this->client->queryParams($from, $to, $mode));
            }

            return $requests;
        });

        foreach ($batch as $key => [$from, $to]) {
            // The pool yields a ConnectionException instead of a Response when a
            // request cannot connect or times out — same outcome: duration unknown.
            $response = $responses[$key];
            $duration = $response instanceof Response
                ? $this->client->durationFromResponse($response)
                : null;

            if ($duration === null) {
                Log::warning('Inter-tour connection could not be routed', [
                    'origin' => $from->toQueryValue(),
                    'destination' => $to->toQueryValue(),
                    'mode' => $mode,
                    'error' => $response instanceof Response
                        ? "HTTP {$response->status()}"
                        : $response->getMessage(),
                ]);
            }
            $this->durationByConnection[$key] = $duration;
        }
    }

    private function connectionKey(Coordinate $from, Coordinate $to, ?string $mode): string
    {
        return $from->key().'>'.$to->key().'@'.($mode ?? '');
    }
}
