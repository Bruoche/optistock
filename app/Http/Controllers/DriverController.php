<?php

namespace App\Http\Controllers;

use App\DTOs\DriverDayData;
use App\Http\Requests\AvailableDriversRequest;
use App\Http\Requests\DriverDayRequest;
use App\Models\Driver;
use App\Repositories\DriverTourRepository;
use App\Services\DayLegsBuilder;
use App\Services\DayWorkdayService;
use App\Services\DriverAvailabilityService;
use Illuminate\Http\JsonResponse;

/** Lists the drivers available for a tour, and serves a specific driver's planned day (025). */
class DriverController extends Controller
{
    /** GET /api/tour/drivers — available drivers plus their chained projected day, chosen start, and workday legs. */
    public function available(AvailableDriversRequest $request, DriverAvailabilityService $availability): JsonResponse
    {
        $rows = $availability->rowsFor(
            $request->validated('mode'),
            $request->date('date')->toDateString(),
            $request->integer('tour'),
        );

        return response()->json(['data' => $rows]);
    }

    /** GET /api/driver/{driver}/day — a driver's planned day: identity, workday totals, ordered tours, neutral legs. */
    public function day(
        DriverDayRequest $request,
        Driver $driver,
        DriverTourRepository $driverTours,
        DayWorkdayService $dayWorkday,
        DayLegsBuilder $legsBuilder,
    ): JsonResponse {
        $driver->loadMissing('deliveryModes', 'warehouse');
        $date = $request->date('date')->toDateString();
        $warehouse = $driver->warehouse->coordinate;

        $assignments = $driverTours->assignmentsForDay($driver, $date);
        $summary = $dayWorkday->summarize($warehouse, $assignments->all());
        $legs = $legsBuilder->build($warehouse, $assignments->all(), $summary['mode']);

        return response()->json([
            'data' => (new DriverDayData($driver, $date, $assignments, $summary, $legs))->toArray(),
        ]);
    }
}
