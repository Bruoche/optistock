<?php

namespace App\Http\Controllers;

use App\Models\Stop;
use App\Models\Tour;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the tour-optimization page for both a fresh tour and editing an existing one
 * (feature 020). The single page is reused; the only difference is the `editTour` prop
 * that hydrates its controls when a tour is being edited.
 */
class TourPageController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('tour/optimize', ['editTour' => null]);
    }

    public function edit(Tour $tour): Response|RedirectResponse
    {
        // A foreign tour is a 404 (never confirm a foreign id exists, as in AssignTourRequest).
        if ((int) $tour->user_id !== (int) request()->user()->id) {
            throw new NotFoundHttpException;
        }

        // An assigned tour is past attribution and not editable (FR-009) — send the planner to a fresh page.
        if ($tour->drivers()->exists()) {
            return redirect()->route('tour.optimize.page');
        }

        $tour->load('deliveryMode', 'stops');

        return Inertia::render('tour/optimize', [
            'editTour' => [
                'id' => $tour->id,
                'mode' => $tour->deliveryMode->label,
                'loop' => $tour->loop,
                'stops' => $tour->stops->map(fn (Stop $stop): array => [
                    'lat' => $stop->latitude,
                    'lng' => $stop->longitude,
                    'duration_minutes' => intdiv($stop->duration_s, 60),
                ])->all(),
            ],
        ]);
    }
}
