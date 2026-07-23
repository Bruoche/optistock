<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Orders a tour's stops as they are driven, given the recorded entry point: rotated to
 * begin at the start for a loop, reversed for a one-way entered at its far end. Mirrors
 * the ordering {@see WorkdayLegsBuilder} applies to a projected tour, factored out so the
 * driver-day view (feature 025) shares one implementation across its legs, list, and markers.
 */
final class DrivenTourStops
{
    /**
     * @template T of object
     *
     * @param  array<int, T>  $stops  ordered by position; each exposes float `latitude`/`longitude`
     * @param  Coordinate  $start  the recorded entry point
     * @return array<int, T> the same stops in driven order
     */
    public static function order(array $stops, Coordinate $start, bool $loop): array
    {
        if ($stops === []) {
            return $stops;
        }

        $startKey = $start->key();

        if ($loop) {
            foreach ($stops as $index => $stop) {
                if (self::keyOf($stop) === $startKey) {
                    return [...array_slice($stops, $index), ...array_slice($stops, 0, $index)];
                }
            }
        } else {
            if (self::keyOf($stops[0]) === $startKey) {
                return $stops;
            }
            if (self::keyOf($stops[array_key_last($stops)]) === $startKey) {
                return array_reverse($stops);
            }
        }

        Log::warning('Assigned tour start matches none of its stops; drawing in stored order', [
            'start' => $start->toQueryValue(),
        ]);

        return $stops;
    }

    private static function keyOf(object $stop): string
    {
        return Coordinate::keyFor((float) $stop->latitude, (float) $stop->longitude);
    }
}
