<?php

namespace App\Services;

use LogicException;

/**
 * Outcome of a tour-optimization request: a ready tour (cache hit, persisted), a
 * pending job to wait on, or a failure to surface. Callers never construct it
 * directly — the factories keep exactly one payload present per state, and the
 * accessors enforce that invariant (a misuse throws instead of leaking null).
 *
 * The `failed` state carries a persistence failure on the synchronous cache-hit
 * path (D10 / FR-014): the route was optimized but could not be saved, so it is
 * surfaced to the user rather than returned as a done-but-unsaved tour.
 */
final class TourOptimizationResult
{
    /**
     * @param  array<string, mixed>|null  $tour
     * @param  array{code: string, message: string}|null  $error
     */
    private function __construct(
        public readonly bool $isReady,
        private readonly ?array $tour,
        private readonly ?string $jobUuid,
        private readonly ?array $error,
    ) {}

    /**
     * @param  array<string, mixed>  $tour
     */
    public static function ready(array $tour): self
    {
        return new self(true, $tour, null, null);
    }

    public static function pending(string $jobUuid): self
    {
        return new self(false, null, $jobUuid, null);
    }

    /**
     * @param  array{code: string, message: string}  $error
     */
    public static function failed(array $error): self
    {
        return new self(false, null, null, $error);
    }

    public function isFailed(): bool
    {
        return $this->error !== null;
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

    /**
     * @return array{code: string, message: string}
     */
    public function error(): array
    {
        return $this->error ?? throw new LogicException('Error requested from a result that did not fail.');
    }
}
