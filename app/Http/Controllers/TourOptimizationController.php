<?php

namespace App\Http\Controllers;

use App\Http\Requests\OptimizeTourRequest;
use App\Services\TourOptimizationService;
use Illuminate\Http\JsonResponse;

/**
 * Non-blocking HTTP entry point for tour optimization.
 *
 * Pure HTTP translation: delegates the normalize → cache → dedup → dispatch
 * orchestration to {@see TourOptimizationService} and maps its result to a
 * response. No domain logic lives here.
 */
class TourOptimizationController extends Controller
{
    /**
     * POST /api/tour/optimize
     *   - cache hit  → 200 with the tour
     *   - cache miss → 202 with a `job_uuid`; the optimized tour arrives later via
     *                  the `TourOptimized` broadcast (or the status endpoint below).
     */
    public function optimizeTour(OptimizeTourRequest $request, TourOptimizationService $tours): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $result = $tours->optimize($userId, $request->validated('coordinates'));

        if ($result->isReady) {
            return response()->json(['status' => 'done', 'data' => $result->tour]);
        }

        return response()->json(['status' => 'pending', 'job_uuid' => $result->jobUuid], 202);
    }

    /**
     * GET /api/tour/status/{job_uuid}
     *   - WebSocket fallback: reports pending / done / failed for a queued request.
     */
    public function getJobStatus(string $jobUuid, TourOptimizationService $tours): JsonResponse
    {
        $jobStatus = $tours->jobStatus($jobUuid);

        if ($jobStatus === null) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json($jobStatus);
    }
}
