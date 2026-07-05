<?php

use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Controllers\TourPageController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::inertia('dashboard', 'dashboard')->name('dashboard');
    });

Route::middleware(['auth'])->group(function () {
    Route::get('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('tour', [TourPageController::class, 'create'])->name('tour.optimize.page');
    Route::get('tour/{tour}/edit', [TourPageController::class, 'edit'])->name('tour.edit.page');
});

require __DIR__.'/settings.php';
