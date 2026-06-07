<?php

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
});
