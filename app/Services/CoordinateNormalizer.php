<?php

namespace App\Services;

/**
 * Canonicalizes a raw list of [lat, lng] pairs into a deterministic, sorted
 * form: each coordinate rounded to {@see PRECISION} decimals (~1.1 m), then
 * stable-sorted, so the same stops in any order produce identical output —
 * letting a sha256 of the result serve as an order-independent cache key.
 */
class CoordinateNormalizer
{
    /** Decimal places kept per coordinate (5 ≈ 1.1 m at the equator). */
    public const PRECISION = 5;

    /**
     * @param  array<int, array{0: int|float|string, 1: int|float|string}>  $coordinates
     * @return array<int, array{lat: float, lng: float}>
     */
    public function normalize(array $coordinates): array
    {
        $normalizedCoordinates = array_map(static fn (array $pair): array => [
            'lat' => round((float) $pair[0], self::PRECISION),
            'lng' => round((float) $pair[1], self::PRECISION),
        ], array_values($coordinates));

        usort(
            $normalizedCoordinates,
            static fn (array $a, array $b): int => [$a['lat'], $a['lng']] <=> [$b['lat'], $b['lng']],
        );

        return $normalizedCoordinates;
    }
}
