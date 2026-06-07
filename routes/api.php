<?php

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
    // per leg. Dedicated limiter, separate from tour-optimize.
    Route::post('tour/geometry', [TourGeometryController::class, 'trace'])
        ->middleware('throttle:tour-geometry')
        ->name('tour.geometry');
});
