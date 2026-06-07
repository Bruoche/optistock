<?php

namespace App\Services;

/**
 * A single geographic point (latitude, longitude). Replaces loose `[lat, lng]`
 * arrays at the boundaries of the route-tracing flow so a stop is a typed value
 * rather than a positional pair.
 */
final class Coordinate
{
    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
    ) {}

    /**
     * Build from a validated `[lat, lng]` request pair.
     *
     * @param  array{0: int|float|string, 1: int|float|string}  $pair
     */
    public static function fromPair(array $pair): self
    {
        return new self((float) $pair[0], (float) $pair[1]);
    }

    /**
     * Render as the `lat,lng` value the OpenStreet query string expects.
     */
    public function toQueryValue(): string
    {
        return $this->lat.','.$this->lng;
    }
}
