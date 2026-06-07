<?php

namespace App\Exceptions;

use App\Jobs\OptimizeTourJob;

/**
 * Raised when the OpenStreet TSP API call cannot produce a usable tour. Caught by
 * {@see OptimizeTourJob}, which broadcasts {@see toPayload()} to the
 * frontend so it can show an appropriate message without leaking internals.
 */
final class TourOptimizationException extends ExternalApiException {}
