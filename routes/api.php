<?php

use App\Http\Controllers\DriverController;
use App\Http\Controllers\DriverUpdateController;
use App\Http\Controllers\TourAssignmentController;
use App\Http\Controllers\TourOrderController;
use App\Http\Controllers\TourForceController;
use App\Http\Controllers\TourGeometryController;
use App\Http\Controllers\TourOptimizationController;
use Illuminate\Support\Facades\Route;

/*
 * JSON tour-optimization endpoints.
 *
 * Registered in bootstrap/app.php under the `/api` prefix with the `web`
 * middleware group, so they authenticate via the same session cookie as the
 * Inertia frontend (no separate API tokens). `throttle:tour-optimize` caps
 * optimization requests at 10/min per user (see AppServiceProvider).
 */
Route::middleware('auth')->group(function (): void {
    Route::post('tour/optimize', [TourOptimizationController::class, 'optimizeTour'])
        ->middleware('throttle:tour-optimize')
        ->name('tour.optimize');

    Route::get('tour/status/{job_uuid}', [TourOptimizationController::class, 'getJobStatus'])
        ->name('tour.status');

    // Hard-write a tour with a manual drive duration (feature 024): the fallback used
    // when optimization is unavailable. Synchronous (no upstream call), so it rides the
    // lightweight read limiter rather than the optimization one.
    Route::post('tour/force', [TourForceController::class, 'force'])
        ->middleware('throttle:tour-read')
        ->name('tour.force');

    // Road-accurate route tracing (feature 002): synchronous; fetches /route geometry
    // per leg. Shared lightweight-read limiter, separate from tour-optimize.
    Route::post('tour/geometry', [TourGeometryController::class, 'trace'])
        ->middleware('throttle:tour-read')
        ->name('tour.geometry');

    // Available drivers for an optimized tour (feature 006): drivers whose modes
    // include the tour's mode, alphabetical.
    Route::get('tour/drivers', [DriverController::class, 'available'])
        ->middleware('throttle:tour-read')
        ->name('tour.drivers');

    // A driver's planned day (feature 025): identity, workday totals, ordered tours,
    // neutral drawable legs — the single read the driver-management page loads.
    Route::get('driver/{driver}/day', [DriverController::class, 'day'])
        ->middleware('throttle:tour-read')
        ->name('driver.day');

    // Update a driver's details (feature 025): name, picture, modes, warehouse. Multipart
    // (image upload) via POST + _method=PATCH. Existing assignments are left untouched.
    Route::patch('driver/{driver}', [DriverUpdateController::class, 'update'])
        ->middleware('throttle:tour-read')
        ->name('driver.update');

    // Reorder a driver's day (feature 025): recompute + persist the new running order, with a
    // force fallback when the routing service is degraded. Blocks/conflicts surfaced as 422/409.
    Route::post('driver/{driver}/tour-order', [TourOrderController::class, 'reorder'])
        ->middleware('throttle:tour-read')
        ->name('driver.tour-order');

    // Assign a persisted tour to a driver (feature 012): records the driver_tour
    // association for the selected date. Trivial write; reuses the read limiter.
    Route::post('tour/{tour}/assign', [TourAssignmentController::class, 'assign'])
        ->middleware('throttle:tour-read')
        ->name('tour.assign');
});
