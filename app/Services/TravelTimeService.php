<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Log;

/** Road travel duration between points, de-duplicated and fetched in capped concurrent batches. */
class TravelTimeService
{
    /** @var array<string, int|null> legKey → duration seconds (null = unknown) */
    private array $durations = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly OpenStreetRouteClient $client,
        private readonly int $poolCap,
    ) {}

    /**
     * Fetch the distinct, not-yet-known legs into the duration map (capped concurrent batches).
     *
     * @param  array<int, array{0: Coordinate, 1: Coordinate}>  $legs
     */
    public function prime(array $legs, ?string $mode = null): void
    {
        $pending = [];
        foreach ($legs as [$from, $to]) {
            $key = $this->legKey($from, $to, $mode);
            if (array_key_exists($key, $this->durations) || isset($pending[$key])) {
                continue;
            }
            if ($from->isSameAs($to)) {
                $this->durations[$key] = 0;

                continue;
            }
            $pending[$key] = [$from, $to];
        }

        foreach (array_chunk($pending, max(1, $this->poolCap), true) as $batch) {
            $this->fetchBatch($batch, $mode);
        }
    }

    /** Road seconds between two points (0 for coincident, null when unroutable). */
    public function durationBetween(Coordinate $from, Coordinate $to, ?string $mode = null): ?int
    {
        if ($from->isSameAs($to)) {
            return 0;
        }

        $key = $this->legKey($from, $to, $mode);
        if (! array_key_exists($key, $this->durations)) {
            $this->prime([[$from, $to]], $mode);
        }

        return $this->durations[$key];
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
            $duration = $this->client->durationFromResponse($responses[$key]);
            if ($duration === null) {
                Log::warning('Inter-tour travel leg failed', [
                    'origin' => $from->toQueryValue(),
                    'destination' => $to->toQueryValue(),
                    'mode' => $mode,
                ]);
            }
            $this->durations[$key] = $duration;
        }
    }

    private function legKey(Coordinate $from, Coordinate $to, ?string $mode): string
    {
        return $from->key().'>'.$to->key().'@'.($mode ?? '');
    }
}
