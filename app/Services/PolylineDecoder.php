<?php

namespace App\Services;

/**
 * Decodes a Google Encoded Polyline string into a list of [lat, lng] pairs.
 *
 * The OpenStreet /route endpoint returns the road geometry of a leg as a Google
 * encoded polyline (precision 5 by default). This reverses that compression so
 * the geometry can be sent to the frontend as plain coordinates.
 *
 * Algorithm reference: https://developers.google.com/maps/documentation/utilities/polylinealgorithm
 */
class PolylineDecoder
{
    /**
     * @return array<int, array{0: float, 1: float}>  ordered [lat, lng] pairs
     */
    public function decode(string $encoded, int $precision = 5): array
    {
        $points = [];
        $index = 0;
        $length = strlen($encoded);
        $lat = 0;
        $lng = 0;
        $factor = 10 ** $precision;

        while ($index < $length) {
            $lat += $this->readDelta($encoded, $index, $length);
            $lng += $this->readDelta($encoded, $index, $length);

            $points[] = [$lat / $factor, $lng / $factor];
        }

        return $points;
    }

    /**
     * Read one zig-zag-encoded varint delta from the string, advancing $index.
     */
    private function readDelta(string $encoded, int &$index, int $length): int
    {
        $shift = 0;
        $result = 0;

        do {
            $byte = ord($encoded[$index++]) - 63;
            $result |= ($byte & 0x1f) << $shift;
            $shift += 5;
        } while ($byte >= 0x20 && $index < $length);

        // Zig-zag decode: odd → negative.
        return ($result & 1) ? ~($result >> 1) : ($result >> 1);
    }
}
