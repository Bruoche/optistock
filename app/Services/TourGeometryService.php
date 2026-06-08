<?php

namespace App\Services;

use App\Exceptions\TourGeometryException;
use Illuminate\Support\Facades\Log;

/**
 * Traces the road geometry of an already-optimized tour.
 *
 * Iterates the consecutive legs, fetches each via {@see OpenStreetRouteClient},
 * and compounds the metrics. For a closed tour the legs include the closing one
 * back to the origin; for an open tour (004) that closing leg is omitted, so the
 * route ends at the last stop and the totals exclude the return. A single leg
 * failing is non-fatal: it is logged and marked `ok:false` so the frontend keeps a
 * straight segment for it (the tour is never blank). Aggregate totals are reported
 * only when every leg succeeded.
 */
class TourGeometryService
{
    public function __construct(private readonly OpenStreetRouteClient $client) {}

    /**
     * @param  array<int, Coordinate>  $orderedStops  visit order
     * @param  bool  $loop  true = closed tour (trace the closing leg); false = open (omit it).
     * @return array{
     *     legs: array<int, array{ok: bool, coordinates?: array<int, array{0: float, 1: float}>, distance_m?: int, duration_s?: int}>,
     *     total_distance_m: int|null,
     *     total_duration_s: int|null
     * }
     */
    public function trace(array $orderedStops, ?string $mode = null, bool $loop = true): array
    {
        $stops = array_values($orderedStops);
        $count = count($stops);
        // Closed: every stop has an outgoing leg, the last returning to the origin.
        // Open: the last stop has no outgoing leg — one fewer leg, no return.
        $legCount = $loop ? $count : $count - 1;
        $legs = [];
        $totalDistance = 0;
        $totalDuration = 0;
        $allOk = true;
        for ($index = 0; $index < $legCount; $index++) {
            $origin = $stops[$index];
            // The closing leg (last → first) only exists for a closed tour.
            $destination = $stops[($index + 1) % $count];
            try {
                $leg = $this->client->traceLeg($origin, $destination, $mode);
                $legs[] = [
                    'ok' => true,
                    'coordinates' => $leg['coordinates'],
                    'distance_m' => $leg['distance_m'],
                    'duration_s' => $leg['duration_s'],
                ];
                $totalDistance += $leg['distance_m'];
                $totalDuration += $leg['duration_s'];
            } catch (TourGeometryException $e) {
                Log::warning('Tour geometry leg failed', [
                    'leg_index' => $index,
                    'origin' => $origin->toQueryValue(),
                    'destination' => $destination->toQueryValue(),
                    'error' => $e->toPayload(),
                ]);
                $legs[] = ['ok' => false];
                $allOk = false;
            }
        }

        return [
            'legs' => $legs,
            'total_distance_m' => $allOk ? $totalDistance : null,
            'total_duration_s' => $allOk ? $totalDuration : null,
        ];
    }
}
