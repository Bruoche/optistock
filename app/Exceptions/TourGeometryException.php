<?php

namespace App\Exceptions;

/**
 * Raised when the OpenStreet /route call for a single leg cannot produce usable
 * geometry. Caught per-leg by the geometry service, which logs it and falls back
 * to a straight segment for that leg.
 */
final class TourGeometryException extends ExternalApiException {}
