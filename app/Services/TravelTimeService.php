<?php

namespace App\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Log;

/**
 * Road travel duration between two points, over the OpenStreet /route endpoint
 * (feature 013). Built for the driver-list load, which needs many inter-tour legs:
 *
 * 1. The caller {@see prime()}s the distinct set of legs it will need. Identical legs
 *    (shared warehouse/return/between legs across drivers) are requested at most once,
 *    and coincident points resolve to a genuine 0 with no call.
 * 2. Outstanding legs are fetched with a **capped, chunked** `Http::pool` — at most
 *    `poolCap` concurrent requests per batch — so the API is sped up without being
 *    flooded / rate-limited.
 * 3. {@see durationBetween()} is then a pure map lookup.
 *
 * A leg that cannot be routed is stored (and read back) as **null** — logged, never
 * silently coerced to 0. Request building and response parsing are reused from
 * {@see OpenStreetRouteClient} so neither is duplicated here.
 */
class TravelTimeService
{
    /** @var array<string, int|null> legKey → duration seconds (null = unknown, 0 = coincident) */
    private array $durations = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly OpenStreetRouteClient $client,
        private readonly int $poolCap,
    ) {}

    /**
     * Fetch every distinct, not-yet-known leg in `$legs` into the duration map.
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

    /**
     * The road duration (seconds) between two points, or null when unknown. Coincident
     * points are 0. Reads the primed map; an un-primed leg is fetched on demand.
     */
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
