<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Http\Requests\AvailableDriversRequest;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;

/**
 * Lists the drivers able to run an optimized tour. Pure HTTP translation: the
 * matching + ordering lives in the Driver `available` scope.
 */
class DriverController extends Controller
{
    /**
     * GET /api/tour/drivers?mode=<trucking|driving|walking>
     *   → drivers whose supported modes include the tour's mode, alphabetical.
     */
    public function available(AvailableDriversRequest $request): JsonResponse
    {
        $mode = DeliveryModeEnum::from($request->validated('mode'));

        $drivers = Driver::available($mode)->get()->map(static fn (Driver $driver): array => [
            'id' => $driver->id,
            'name' => $driver->name,
            'image_url' => $driver->image_url,
            'modes' => $driver->deliveryModes->pluck('label')->all(),
        ]);

        return response()->json(['data' => $drivers]);
    }
}
