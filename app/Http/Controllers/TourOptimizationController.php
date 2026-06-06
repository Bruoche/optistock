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
        // sha256 of the canonical coordinates → order-independent cache key.
        $coordinatesHash = hash('sha256', (string) json_encode($normalizedCoordinates));

        $cachedTour = $cache->getTour($userId, $coordinatesHash);

        if ($cachedTour !== null) {
            return response()->json(['status' => 'done', 'data' => $cachedTour]);
        }

        $jobUuid = (string) Str::uuid();

        // Dedup concurrent identical requests. claimActiveJob atomically reserves
        // the slot for this coordinate set: true = we won it and must dispatch,
        // false = an identical optimization is already running.
        $wonClaim = $cache->claimActiveJob($userId, $coordinatesHash, $jobUuid);

        if (! $wonClaim) {
            $runningJobUuid = $cache->getActiveJob($userId, $coordinatesHash);

            // Reuse the running job so we never fire a second multi-minute upstream
            // call; the frontend waits on the same broadcast / polls the same status.
            if ($runningJobUuid !== null) {
                return response()->json(['status' => 'pending', 'job_uuid' => $runningJobUuid], 202);
            }

            // Rare race: that job released its slot between our failed claim and
            // this read. Fall through and enqueue a fresh job.
        }

        $cache->markPending($jobUuid);

        OptimizeTourJob::dispatch($jobUuid, $userId, $coordinatesHash, $normalizedCoordinates);

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
