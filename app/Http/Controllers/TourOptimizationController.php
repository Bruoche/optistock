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
 */
class TourOptimizationController extends Controller
{
	/**
	 * POST /api/tour/optimize
	 *   - cache hit  → 200 with the tour immediately
	 *   - cache miss → 202 with a `job_uuid`; the optimized tour arrives later via the `TourOptimized` broadcast 
	 * 		(or the status endpoint below).
	 * @param OptimizeTourRequest $request
	 * @param CoordinateNormalizer $normalizer
	 * @param TourCache $cache
	 * @return JsonResponse
	 */
    public function optimizeTour(OptimizeTourRequest $request, CoordinateNormalizer $normalizer, TourCache $cache): JsonResponse 
	{
        $userId = (int) $request->user()->id;
        $normalizedCoordinates = $normalizer->normalize($request->validated('coordinates'));
        $coordinatesHash = hash('sha256', (string) json_encode($normalizedCoordinates));
        $cachedTour = $cache->getTour($coordinatesHash); // -> order-independent cache key.
        if ($cachedTour !== null) {
            return response()->json(['status' => 'done', 'data' => $cachedTour]);
        }

        $jobUuid = (string) Str::uuid();
        $wonClaim = $cache->claimActiveJob($userId, $coordinatesHash, $jobUuid);
        if (! $wonClaim) { // if an identical optimization is already running.
            $runningJobUuid = $cache->getActiveJob($userId, $coordinatesHash);
            if ($runningJobUuid !== null) {
            	// Reuse the running job so we never fire a second multi-minute upstream call;
                return response()->json(['status' => 'pending', 'job_uuid' => $runningJobUuid], 202);
            }
            // Rare case: that job released its slot between our failed claim and this read. Fall through and enqueue a fresh job.
        }

        $cache->markPending($jobUuid);
        OptimizeTourJob::dispatch($jobUuid, $userId, $coordinatesHash, $normalizedCoordinates);
        return response()->json(['status' => 'pending', 'job_uuid' => $jobUuid], 202);
    }

	/**
	 * GET /api/tour/status/{job_uuid}
     *   - WebSocket fallback: reports pending / done / failed for a queued request.
	 * @param string $jobUuid
	 * @param TourCache $cache
	 * @return JsonResponse
	 */
    public function getJobStatus(string $jobUuid, TourCache $cache): JsonResponse
    {
        $jobStatus = $cache->getJobStatus($jobUuid);
        if ($jobStatus === null) {
            return response()->json(['status' => 'not_found'], 404);
        }
        return response()->json($jobStatus);
    }
}
