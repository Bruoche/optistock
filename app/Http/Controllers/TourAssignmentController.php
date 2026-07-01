<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignTourRequest;
use App\Models\Tour;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

/**
 * Records the assignment of a persisted tour to a driver.
 *
 * Ownership + driver eligibility are enforced by {@see AssignTourRequest}. The
 * assignment is a single `driver_tour` row keyed by the unique `tour_id`, so
 * `sync` makes it idempotent now and a same-row update on a future re-assignment.
 */
class TourAssignmentController extends Controller
{
    /**
     * POST /api/tour/{tour}/assign
     */
    public function assign(AssignTourRequest $request, Tour $tour): JsonResponse
    {
        $driverId = (int) $request->validated('driver_id');
        $date = $request->validated('date');

        try {
            $tour->drivers()->sync([$driverId => ['date' => $date]]);
        } catch (QueryException $e) {
            // A concurrent double-assign can race the unique tour_id constraint; the
            // row it collided with is the same assignment, so treat it as success.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        return response()->json(['data' => [
            'tour_id' => $tour->id,
            'driver_id' => $driverId,
            'date' => $date,
        ]]);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000' || $e->getCode() === '23505';
    }
}
