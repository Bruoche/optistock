<?php

namespace App\Services;

use App\Exceptions\TourGeometryException;
use Illuminate\Support\Facades\Log;

/**
 * Traces the road geometry of an already-optimized closed tour.
 *
 * Iterates the consecutive legs (including the closing leg back to the origin),
 * fetches each via {@see OpenStreetRouteClient}, and compounds the metrics. A
 * single leg failing is non-fatal: it is logged and marked `ok:false` so the
 * frontend keeps a straight segment for it (the tour is never blank). Aggregate
 * totals are reported only when every leg succeeded.
 */
class TourGeometryService
{
    public function __construct(private readonly OpenStreetRouteClient $client) {}

    /**
     * @param  array<int, array{lat: float, lng: float}>  $orderedStops  visit order
     * @return array{
     *     legs: array<int, array{ok: bool, coordinates?: array<int, array{0: float, 1: float}>, distance_m?: int, duration_s?: int}>,
     *     total_distance_m: int|null,
     *     total_duration_s: int|null
     * }
     */
    public function trace(array $orderedStops, ?string $mode = null): array
    {
        $stops = array_values($orderedStops);
        $count = count($stops);

        $legs = [];
        $totalDistance = 0;
        $totalDuration = 0;
        $allOk = true;

        foreach ($stops as $index => $origin) {
            // Closed tour: the last leg returns to the first stop.
            $destination = $stops[($index + 1) % $count];

            try {
                $leg = $this->client->route($origin, $destination, $mode);
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
                    'origin' => $origin,
                    'destination' => $destination,
                    'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()],
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
