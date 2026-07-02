<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignTourRequest;
use App\Models\Tour;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
        $date = $request->date('date')->toDateString();
        $startIndex = (int) $request->validated('start_index');

        $startStop = $tour->stops->firstWhere('position', $startIndex);
        $endStop = $tour->endStopForStart($startStop);
        $sequence = $this->nextSequence($driverId, $date);

        $pivot = [
            'date' => $date,
            'start_latitude' => $startStop->latitude,
            'start_longitude' => $startStop->longitude,
            'end_latitude' => $endStop->latitude,
            'end_longitude' => $endStop->longitude,
            'sequence' => $sequence,
        ];

        try {
            // sync detaches any prior driver + attaches this one; wrapped in a
            // transaction so a concurrent race that violates the unique tour_id can
            // roll back cleanly rather than leaving the tour half-detached.
            DB::transaction(fn () => $tour->drivers()->sync([$driverId => $pivot]));
        } catch (QueryException $e) {
            // A concurrent double-assign raced the unique tour_id constraint; the row
            // it collided with is the same assignment, so treat it as success.
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
        }

        return response()->json(['data' => [
            'tour_id' => $tour->id,
            'driver_id' => $driverId,
            'date' => $date,
            'start_index' => $startIndex,
            'sequence' => $sequence,
        ]]);
    }

    /** One past the driver's current latest tour for the date (0 when they have none). */
    private function nextSequence(int $driverId, string $date): int
    {
        $current = DB::table('driver_tour')
            ->where('driver_id', $driverId)
            ->where('date', $date)
            ->max('sequence');

        return $current === null ? 0 : (int) $current + 1;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000' || $e->getCode() === '23505';
    }
}
