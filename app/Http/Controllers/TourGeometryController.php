<?php

namespace App\Http\Controllers;

use App\Http\Requests\TourGeometryRequest;
use App\Models\Tour;
use App\Services\Coordinate;
use App\Services\TourGeometryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Synchronous HTTP entry point for road-accurate route tracing (feature 002).
 *
 * Thin: maps the request's ordered stops to {@see TourGeometryService::trace}
 * and returns the aggregated per-leg geometry + compounded metrics. When a
 * `tour_id` is supplied it also finalizes that tour's road totals as a side
 * effect (kept here so the trace service stays pure). No domain logic here.
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
        if ($tourId !== null) {
            $this->persistRoadTotals((int) $tourId, (int) $request->user()->id, $trace);
        }

        return response()->json($trace);
    }

    /**
     * Finalize a persisted tour's road totals with the traced values. A tour that is
     * unknown, not owned by the user, or a trace with no aggregate totals (a leg
     * failed) leaves the seed untouched — logged, never surfaced: the tour is already
     * saved and still assignable, so this refinement failure must not fail the trace
     * (D10/RB4).
     *
     * @param  array{legs: array<int, mixed>, total_distance_m: int|null, total_duration_s: int|null}  $trace
     */
    private function persistRoadTotals(int $tourId, int $userId, array $trace): void
    {
        if ($trace['total_duration_s'] === null || $trace['total_distance_m'] === null) {
            Log::info('Geometry persist skipped: trace produced no totals', ['tour_id' => $tourId]);

            return;
        }

        $tour = Tour::where('id', $tourId)->where('user_id', $userId)->first();
        if ($tour === null) {
            Log::info('Geometry persist skipped: tour not found or not owned', [
                'tour_id' => $tourId,
                'user_id' => $userId,
            ]);

            return;
        }

        try {
            $tour->update([
                'travel_duration_s' => $trace['total_duration_s'],
                'total_distance_m' => $trace['total_distance_m'],
            ]);
        } catch (Throwable $e) {
            Log::warning('Geometry persist failed', ['tour_id' => $tourId, 'error' => $e->getMessage()]);
        }
    }
}
