<?php

namespace App\Services;

/**
 * Turns a raw list of [lat, lng] pairs into a canonical, deterministic form
 * plus a stable hash used as the cache key.
 *
 * Coordinates are rounded to {@see PRECISION} decimals (~1.1 m) and sorted, so
 * the same set of stops submitted in any order yields the same hash — letting
 * us serve a cached optimized route regardless of input ordering (TSP reorders
 * the stops anyway).
 */
class RouteNormalizer
{
    /** Decimal places kept per coordinate (5 ≈ 1.1 m at the equator). */
    public const PRECISION = 5;

    /**
     * @param  array<int, array{0: int|float|string, 1: int|float|string}>  $coordinates
     * @return array{coordinates: array<int, array{lat: float, lng: float}>, hash: string}
     */
    public function normalize(array $coordinates): array
    {
        $normalized = array_map(static fn (array $pair): array => [
            'lat' => round((float) $pair[0], self::PRECISION),
            'lng' => round((float) $pair[1], self::PRECISION),
        ], array_values($coordinates));

        usort(
            $normalized,
            static fn (array $a, array $b): int => [$a['lat'], $a['lng']] <=> [$b['lat'], $b['lng']],
        );

        $hash = hash('sha256', (string) json_encode($normalized));

        return ['coordinates' => $normalized, 'hash' => $hash];
    }
}
