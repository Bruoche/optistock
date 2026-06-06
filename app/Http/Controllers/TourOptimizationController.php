<?php

namespace App\Http\Controllers;

use App\Http\Requests\OptimizeTourRequest;
use App\Jobs\OptimizeTourJob;
use App\Services\CoordinateNormalizer;
use App\Services\TourCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Non-blocking entry point for tour optimization.
 *
 * POST /api/tour/optimize
 *   - cache hit  → 200 with the tour immediately
 *   - cache miss → 202 with a `job_uuid`; the optimized tour arrives later via
 *                  the `TourOptimized` broadcast (or the status endpoint below).
 *
 * GET /api/tour/status/{job_uuid}
 *   - WebSocket fallback: reports pending / done / failed for a queued request.
 */
class TourOptimizationController extends Controller
{
    public function optimizeTour(
        OptimizeTourRequest $request,
        CoordinateNormalizer $normalizer,
        TourCache $cache,
    ): JsonResponse {
        $userId = (int) $request->user()->id;
        $normalizedCoordinates = $normalizer->normalize($request->validated('coordinates'));

        if ($cachedTour = $cache->getTour($userId, $normalizedCoordinates['hash'])) {
            return response()->json(['status' => 'done', 'data' => $cachedTour]);
        }

        $jobUuid = (string) Str::uuid();

        // Dedup concurrent identical requests: if an optimization for this exact
        // coordinate set is already running, reuse its job_uuid instead of firing
        // a second multi-minute upstream call. The frontend can wait on the same
        // broadcast / poll the same status.
        if (! $cache->claimActiveJob($userId, $normalizedCoordinates['hash'], $jobUuid)) {
            if ($existingJobUuid = $cache->getActiveJob($userId, $normalizedCoordinates['hash'])) {
                return response()->json(['status' => 'pending', 'job_uuid' => $existingJobUuid], 202);
            }
        }

        $cache->markPending($jobUuid);

        OptimizeTourJob::dispatch(
            $jobUuid,
            $userId,
            $normalizedCoordinates['hash'],
            $normalizedCoordinates['coordinates'],
        );

        return response()->json(['status' => 'pending', 'job_uuid' => $jobUuid], 202);
    }

    public function getJobStatus(string $jobUuid, TourCache $cache): JsonResponse
    {
        $jobStatus = $cache->getJobStatus($jobUuid);

        if ($jobStatus === null) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json($jobStatus);
    }
}
