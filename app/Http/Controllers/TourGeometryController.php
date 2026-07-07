<?php

namespace App\Http\Controllers;

use App\Http\Requests\TourGeometryRequest;
use App\Services\Coordinate;
use App\Services\TourGeometryService;
use Illuminate\Http\JsonResponse;

/**
 * Synchronous HTTP entry point for road-accurate route tracing (feature 002).
 *
 * Thin: maps the request's ordered stops to {@see TourGeometryService::trace} and
 * returns the aggregated per-leg geometry + compounded metrics. When a `tour_id` is
 * supplied the service also finalizes that tour's road totals. No domain logic here.
 * Geometry is a pure enhancement over the 001 result — see
 * specs/002-road-accurate-route-tracing/contracts/tour-geometry.md.
 */
class TourGeometryController extends Controller
{
    /**
     * POST /api/tour/geometry
     */
    public function trace(TourGeometryRequest $request, TourGeometryService $geometry): JsonResponse
    {
        $stops = array_map(Coordinate::fromPair(...), $request->validated('stops'));
        $mode = $request->validated('mode') ?? config('services.openstreet.mode');
        // No loop in the request → default to a closed tour (trace the return leg).
        $loop = $request->boolean('loop', true);

        $trace = $geometry->trace($stops, $mode, $loop);

        $tourId = $request->validated('tour_id');
        $geometry->finalizeTourTotals(
            $tourId === null ? null : (int) $tourId,
            (int) $request->user()->id,
            $trace,
        );

        return response()->json($trace);
    }
}
