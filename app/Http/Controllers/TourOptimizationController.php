<?php

namespace App\Http\Controllers;

use App\Http\Requests\OptimizeTourRequest;
use App\Services\TourOptimizationResult;
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
     *   - cache hit  → 200 `done` with the persisted tour (id included)
     *   - cache hit but the tour could not be saved → 200 `failed` (`persist_failed`)
     *   - cache miss → 202 with a `job_uuid`; the optimized tour arrives later via
     *                  the `TourOptimized` broadcast (or the status endpoint below).
     */
    public function optimizeTour(OptimizeTourRequest $request, TourOptimizationService $tours): JsonResponse
    {
        $userId = (int) $request->user()->id;
        // No mode in the request → fall back to the configured default (trucking).
        $mode = $request->validated('mode') ?? config('services.openstreet.mode');
        // No loop in the request → default to a closed tour (return to origin).
        $loop = $request->boolean('loop', true);
        // Present → update this existing tour in place instead of creating one (feature 020).
        $editTourId = $request->validated('tour_id');

        $result = $tours->optimize($userId, $request->validated('stops'), $mode, $loop, $editTourId === null ? null : (int) $editTourId);

        return $this->respondTo($result);
    }

    private function respondTo(TourOptimizationResult $result): JsonResponse
    {
        if ($result->isReady) {
            return response()->json(['status' => 'done', 'data' => $result->tour()]);
        }

        // Cache-hit persistence failure: surfaced to the client (same shape as the
        // poll/broadcast settle) rather than a raw 500 (D10/FR-014).
        if ($result->isFailed()) {
            return response()->json(['status' => 'failed', 'error' => $result->error()]);
        }

        return response()->json(['status' => 'pending', 'job_uuid' => $result->jobUuid()], 202);
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
