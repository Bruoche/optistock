<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignTourRequest;
use App\Models\Tour;
use App\Services\TourAssignmentService;
use Illuminate\Http\JsonResponse;

/**
 * Records the assignment of a persisted tour to a driver. Ownership + driver
 * eligibility are enforced by {@see AssignTourRequest}; the assignment itself is
 * handled by {@see TourAssignmentService}.
 */
class TourAssignmentController extends Controller
{
    /**
     * POST /api/tour/{tour}/assign
     */
    public function assign(AssignTourRequest $request, Tour $tour, TourAssignmentService $assignments): JsonResponse
    {
        $data = $assignments->assign(
            $tour,
            (int) $request->validated('driver_id'),
            $request->date('date')->toDateString(),
            (int) $request->validated('start_index'),
        );

        return response()->json(['data' => $data]);
    }
}
