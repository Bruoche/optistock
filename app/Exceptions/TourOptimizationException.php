<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when the OpenStreet TSP API call cannot produce a usable tour.
 *
 * The {@see $errorCode} is a stable, client-safe identifier broadcast to the
 * frontend so it can show an appropriate message without leaking internals.
 */
class TourOptimizationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function timeout(string $message, ?Throwable $previous = null): self
    {
        return new self('timeout', $message, $previous);
    }

    public static function apiError(string $message): self
    {
        return new self('api_error', $message);
    }

    public static function invalidResponse(string $message): self
    {
        return new self('invalid_response', $message);
    }

    /**
     * @return array{code: string, message: string}
     */
    public function toPayload(): array
    {
        return ['code' => $this->errorCode, 'message' => $this->getMessage()];
    }
}
