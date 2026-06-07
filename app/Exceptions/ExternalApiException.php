<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base for failures from an external OpenStreet API call.
 *
 * Carries a stable, client-safe {@see $errorCode} and the shared factories for
 * the common failure modes. Concrete subclasses add no behaviour — they exist so
 * each feature has its own type to catch (e.g. the optimization job catches
 * {@see TourOptimizationException}, the geometry service catches
 * {@see TourGeometryException}).
 */
abstract class ExternalApiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function timeout(int $seconds, Throwable $previous): static
    {
        return new static(
            'timeout',
            "OpenStreet API did not respond within {$seconds}s: {$previous->getMessage()}",
            $previous,
        );
    }

    public static function apiError(string $message): static
    {
        return new static('api_error', $message);
    }

    public static function invalidResponse(string $message): static
    {
        return new static('invalid_response', $message);
    }

    /**
     * @return array{code: string, message: string}
     */
    public function toPayload(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage()];
    }
}
