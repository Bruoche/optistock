<?php

namespace App\Http\Controllers;

use App\Models\Driver;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves the driver-management page (feature 025). The heavy day data is fetched
 * client-side from the driver-day endpoint so switching days needs no Inertia visit;
 * only the driver handle, the initial date, and the warehouse options ship as props.
 * The mode list comes from the frontend `DELIVERY_MODES` constant (the enum has no labels).
 */
class DriverPageController extends Controller
{
    public function manage(Driver $driver, Request $request): Response
    {
        return Inertia::render('driver/manage', [
            'driverId' => $driver->id,
            'initialDate' => $request->string('date')->value() ?: now()->toDateString(),
            'warehouses' => Warehouse::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
