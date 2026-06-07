<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Raised when the OpenStreet /route call for a single leg cannot produce usable
 * geometry. Caught per-leg by the geometry service, which logs it and falls back
 * to a straight segment for that leg.
 */
class TourGeometryException extends RuntimeException
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
}
