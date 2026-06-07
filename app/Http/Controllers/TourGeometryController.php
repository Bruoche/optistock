<?php

namespace App\Http\Controllers;

use App\Http\Requests\TourGeometryRequest;
use App\Services\TourGeometryService;
use Illuminate\Http\JsonResponse;

/**
 * Synchronous HTTP entry point for road-accurate route tracing (feature 002).
 *
 * Thin: maps the request's ordered stops to {@see TourGeometryService::trace}
 * and returns the aggregated per-leg geometry + compounded metrics. No domain
 * logic here. Geometry is a pure enhancement over the 001 result — see
 * specs/002-road-accurate-route-tracing/contracts/tour-geometry.md.
 */
class TourGeometryController extends Controller
{
    /**
     * POST /api/tour/geometry
     */
    public function trace(TourGeometryRequest $request, TourGeometryService $geometry): JsonResponse
    {
        $stops = array_map(
            static fn (array $pair): array => ['lat' => (float) $pair[0], 'lng' => (float) $pair[1]],
            $request->validated('stops'),
        );

        // No user-facing mode selector yet: fall back to the configured default.
        $mode = $request->validated('mode') ?? config('services.openstreet.mode');

        return response()->json($geometry->trace($stops, $mode));
    }
}
