<?php

use App\Http\Controllers\RouteOptimizationController;
use Illuminate\Support\Facades\Route;

/*
 * JSON route-optimization endpoints.
 *
 * Registered in bootstrap/app.php under the `/api` prefix with the `web`
 * middleware group, so they authenticate via the same session cookie as the
 * Inertia frontend (no separate API tokens). `throttle:route-optimize` caps
 * optimization requests at 10/min per user (see AppServiceProvider).
 */
Route::middleware('auth')->group(function (): void {
    Route::post('route/optimize', [RouteOptimizationController::class, 'store'])
        ->middleware('throttle:route-optimize')
        ->name('route.optimize');

    Route::get('route/result/{job_uuid}', [RouteOptimizationController::class, 'result'])
        ->name('route.result');
});
