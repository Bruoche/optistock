<?php

use App\Http\Controllers\DriverController;
use App\Http\Controllers\TourAssignmentController;
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

    // Assign a persisted tour to a driver (feature 012): records the driver_tour
    // association for the selected date. Trivial write; reuses the read limiter.
    Route::post('tour/{tour}/assign', [TourAssignmentController::class, 'assign'])
        ->middleware('throttle:tour-read')
        ->name('tour.assign');
});
