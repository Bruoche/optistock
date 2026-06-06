<?php

namespace App\Services;

/**
 * Outcome of a tour-optimization request: either a ready tour (cache hit) or a
 * pending job to wait on. Deliberately hides whether the pending job was freshly
 * dispatched or an existing one reused — callers only need ready-or-pending.
 */
final class TourOptimizationResult
{
    /**
     * @param  array<string, mixed>|null  $tour
     */
    private function __construct(
        public readonly bool $isReady,
        public readonly ?array $tour,
        public readonly ?string $jobUuid,
    ) {}

    /**
     * @param  array<string, mixed>  $tour
     */
    public static function ready(array $tour): self
    {
        return new self(true, $tour, null);
    }

    public static function pending(string $jobUuid): self
    {
        return new self(false, null, $jobUuid);
    }
}
