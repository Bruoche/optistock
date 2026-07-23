<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when a normal (optimal) tour-order recompute needs a connection the routing
 * service cannot measure (feature 025). Carries the failed leg so the caller can report
 * it and offer a force-save. Never thrown on the force path, which uses no routing.
 */
class UnroutableConnectionException extends RuntimeException
{
    public function __construct(
        public readonly Coordinate $from,
        public readonly Coordinate $to,
    ) {
        parent::__construct('A connection required to reorder the day could not be routed.');
    }
}
