<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForceTourRequest;
use App\Services\TourForcingService;
use Illuminate\Http\JsonResponse;

/**
 * Synchronous HTTP entry point for hard-writing a tour (feature 024).
 *
 * The fallback for when optimization is unavailable: no upstream call, no queue —
 * it saves the stops in their current order with a manually entered drive duration
 * and returns the same `done`/`failed` shape as the optimize cache-hit path, so the
 * frontend settles a forced tour through its existing result flow.
 */
class TourForceController extends Controller
{
    /**
     * POST /api/tour/force
     *   - saved   → 200 `done` with the persisted tour (id included, distance null)
     *   - unsaved → 200 `failed` (`persist_failed`) — e.g. a vanished edit target
     */
    public function force(ForceTourRequest $request, TourForcingService $tours): JsonResponse
    {
        $userId = (int) $request->user()->id;
        // No mode in the request → fall back to the configured default (trucking).
        $mode = $request->validated('mode') ?? config('services.openstreet.mode');
        // No loop in the request → default to a closed tour (return to origin).
        $loop = $request->boolean('loop', true);
        // Present → overwrite this existing tour in place instead of creating one (feature 020).
        $editTourId = $request->validated('tour_id');

        $result = $tours->force(
            $userId,
            $request->validated('stops'),
            $mode,
            $loop,
            (int) $request->validated('travel_duration_s'),
            $editTourId === null ? null : (int) $editTourId,
        );

        if ($result->isReady) {
            return response()->json(['status' => 'done', 'data' => $result->tour()]);
        }

        return response()->json(['status' => 'failed', 'error' => $result->error()]);
    }
}
