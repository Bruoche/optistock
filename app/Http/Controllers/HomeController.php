<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

/** Serves the application root (feature 028): the dashboard for signed-in users, the welcome page for guests. */
class HomeController extends Controller
{
    public function index(Request $request): Response
    {
        if ($request->user() !== null) {
            return Inertia::render('home');
        }

        return Inertia::render('welcome', [
            'canRegister' => Features::enabled(Features::registration()),
        ]);
    }
}
