<?php

namespace App\Services;

use LogicException;

/**
 * Outcome of a tour-optimization request: either a ready tour (cache hit) or a
 * pending job to wait on. Deliberately hides whether the pending job was freshly
 * dispatched or an existing one reused — callers only need ready-or-pending.
 *
 * The two states are mutually exclusive and total: a result is constructed via
 * {@see ready()} or {@see pending()} (never directly), so exactly one payload is
 * always present. The accessors enforce that invariant and return non-null, so
 * callers never handle a nullable field — a misuse throws instead of leaking null.
 */
final class TourOptimizationResult
{
    /**
     * @param  array<string, mixed>|null  $tour
     */
    private function __construct(
        public readonly bool $isReady,
        private readonly ?array $tour,
        private readonly ?string $jobUuid,
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

    /**
     * @return array<string, mixed>
     */
    public function tour(): array
    {
        return $this->tour ?? throw new LogicException('Tour requested from a result that is not ready.');
    }

    public function jobUuid(): string
    {
        return $this->jobUuid ?? throw new LogicException('Job UUID requested from a result that is ready.');
    }
}
