<?php

namespace App\Http\Controllers;

use App\Http\Requests\AvailableDriversRequest;
use App\Services\DriverAvailabilityService;
use Illuminate\Http\JsonResponse;

/** Lists the drivers available for a tour with each one's projected working day if given it. */
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
}
