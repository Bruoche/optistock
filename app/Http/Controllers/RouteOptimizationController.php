<?php

namespace App\Http\Controllers;

use App\Http\Requests\OptimizeRouteRequest;
use App\Jobs\OptimizeRouteJob;
use App\Services\RouteCache;
use App\Services\RouteNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Non-blocking entry point for route optimization.
 *
 * POST /api/route/optimize
 *   - cache hit  → 200 with the route immediately
 *   - cache miss → 202 with a `job_uuid`; the optimized route arrives later via
 *                  the `RouteOptimized` broadcast (or the polling endpoint below).
 *
 * GET /api/route/result/{job_uuid}
 *   - WebSocket fallback: reports pending / done / failed for a queued request.
 */
class RouteOptimizationController extends Controller
{
    public function store(
        OptimizeRouteRequest $request,
        RouteNormalizer $normalizer,
        RouteCache $cache,
    ): JsonResponse {
        $userId = (int) $request->user()->id;
        $normalized = $normalizer->normalize($request->validated('coordinates'));

        if ($result = $cache->getResult($userId, $normalized['hash'])) {
            return response()->json(['status' => 'done', 'data' => $result]);
        }

        $jobUuid = (string) Str::uuid();

        // Dedup concurrent identical requests: if an optimization for this exact
        // coordinate set is already running, reuse its job_uuid instead of firing
        // a second multi-minute upstream call. The frontend can wait on the same
        // broadcast / poll the same status.
        if (! $cache->claimInflight($userId, $normalized['hash'], $jobUuid)) {
            if ($existing = $cache->getInflight($userId, $normalized['hash'])) {
                return response()->json(['status' => 'pending', 'job_uuid' => $existing], 202);
            }
        }

        $cache->markPending($jobUuid);

        OptimizeRouteJob::dispatch(
            $jobUuid,
            $userId,
            $normalized['hash'],
            $normalized['coordinates'],
        );

        return response()->json(['status' => 'pending', 'job_uuid' => $jobUuid], 202);
    }

    public function result(string $jobUuid, RouteCache $cache): JsonResponse
    {
        $status = $cache->getStatus($jobUuid);

        if ($status === null) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json($status);
    }
}
