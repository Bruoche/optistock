<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Enums\WeekDay as WeekDayEnum;
use App\Http\Requests\AvailableDriversRequest;
use App\Models\Driver;
use Illuminate\Http\JsonResponse;

/**
 * Lists the drivers able to run an optimized tour. Pure HTTP translation: the
 * matching + ordering lives in the Driver `available` scope. The tour's weekday is
 * deduced here from the request date, so the front-end's own weekday calculation
 * (used only for its label) can never affect which drivers are returned.
 */
class DriverController extends Controller
{
    /**
     * GET /api/tour/drivers?mode=<trucking|driving|walking>&date=<YYYY-MM-DD>
     *   → drivers whose supported modes include the tour's mode and whose schedule
     *     includes the date's weekday, alphabetical.
     */
    public function available(AvailableDriversRequest $request): JsonResponse
    {
        $mode = DeliveryModeEnum::from($request->validated('mode'));
        $date = $request->date('date')->toDateString();
        $day = WeekDayEnum::fromDate($request->date('date'));

        $committedSeconds = Driver::committedSecondsForDate($date);

        $drivers = Driver::available($mode, $day)->get()->map(static fn (Driver $driver): array => [
            'id' => $driver->id,
            'name' => $driver->name,
            'image_url' => $driver->image_url,
            'modes' => $driver->deliveryModes->pluck('label')->all(),
            'assigned_seconds' => $committedSeconds[$driver->id] ?? 0,
        ]);

        return response()->json(['data' => $drivers]);
    }
}
