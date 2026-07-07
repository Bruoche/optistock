<?php

namespace App\Services;

/**
 * The normalized inputs a tour-optimization request is keyed and dispatched on:
 * the rounded coordinates, their cache hash, and each coordinate's queued delivery
 * durations (re-attached to the stops after the TSP reorder).
 */
final class OptimizationInputs
{
    /**
     * @param  array<int, array{0: float, 1: float}>  $normalizedCoordinates
     * @param  array<string, list<int>>  $durationsByCoordinate
     */
    public function __construct(
        public readonly array $normalizedCoordinates,
        public readonly string $coordinatesHash,
        public readonly array $durationsByCoordinate,
    ) {}
}
