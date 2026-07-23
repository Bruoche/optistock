<?php

namespace App\Http\Controllers;

use App\DTOs\DriverDayData;
use App\Http\Requests\ReorderToursRequest;
use App\Models\Driver;
use App\Repositories\DriverTourRepository;
use App\Services\DayLegsBuilder;
use App\Services\DayWorkdayService;
use App\Services\TourOrderService;
use App\Services\UnroutableConnectionException;
use Illuminate\Http\JsonResponse;

/**
 * Saves a new running order for a driver's day (feature 025). A normal save recomputes and
 * re-optimises each tour's entry/exit and is blocked when a connection cannot be routed; a
 * forced save persists the order with a routing-free fallback.
 */
class TourOrderController extends Controller
{
    public function reorder(
        ReorderToursRequest $request,
        Driver $driver,
        DriverTourRepository $driverTours,
        TourOrderService $tourOrder,
        DayWorkdayService $dayWorkday,
        DayLegsBuilder $legsBuilder,
    ): JsonResponse {
        $date = $request->date('date')->toDateString();
        $orderedTourIds = array_map('intval', $request->validated('tour_ids'));

        // Conflict (FR-034): the submitted set must match the day's current assignments, or a
        // concurrent assign/removal has changed the day — refuse and let the client refresh.
        $current = $driverTours->assignedTourIds($driver, $date);
        if (array_diff($orderedTourIds, $current) !== [] || array_diff($current, $orderedTourIds) !== []) {
            return response()->json([
                'code' => 'assignment_conflict',
                'message' => 'This day changed elsewhere. It has been refreshed.',
            ], 409);
        }

        try {
            $rows = $tourOrder->reorder($driver, $orderedTourIds, $request->boolean('force'));
        } catch (UnroutableConnectionException $e) {
            return response()->json([
                'code' => 'unroutable_connection',
                'message' => 'A drive on this order could not be routed. Force the save to keep the order.',
                'failed_leg' => [
                    'from' => [$e->from->lat, $e->from->lng],
                    'to' => [$e->to->lat, $e->to->lng],
                ],
            ], 422);
        }

        $driverTours->reorder($driver, $date, $rows);

        return response()->json([
            'data' => $this->freshDay($driver, $date, $driverTours, $dayWorkday, $legsBuilder),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function freshDay(
        Driver $driver,
        string $date,
        DriverTourRepository $driverTours,
        DayWorkdayService $dayWorkday,
        DayLegsBuilder $legsBuilder,
    ): array {
        $driver->loadMissing('deliveryModes', 'warehouse');
        $warehouse = $driver->warehouse->coordinate;

        $assignments = $driverTours->assignmentsForDay($driver, $date);
        $summary = $dayWorkday->summarize($warehouse, $assignments->all());
        $legs = $legsBuilder->build($warehouse, $assignments->all(), $summary['mode']);

        return (new DriverDayData($driver, $date, $assignments, $summary, $legs))->toArray();
    }
}
