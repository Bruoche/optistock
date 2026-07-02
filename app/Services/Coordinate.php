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

    /** Identity key rounded to the normalizer's precision (coordinates within ~1.1 m compare equal). */
    public function key(): string
    {
        return self::keyFor($this->lat, $this->lng);
    }

    /** Build the rounded identity key for a raw lat/lng pair. */
    public static function keyFor(float $lat, float $lng): string
    {
        $precision = CoordinateNormalizer::PRECISION;

        return sprintf('%.'.$precision.'f,%.'.$precision.'f', $lat, $lng);
    }

    /** Whether both coordinates resolve to the same rounded point. */
    public function isSameAs(self $other): bool
    {
        return $this->key() === $other->key();
    }
}
