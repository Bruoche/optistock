<?php

namespace App\Http\Controllers;

use App\DTOs\EditTourData;
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
        if ($tour->isAssigned()) {
            return redirect()->route('tour.optimize.page');
        }

        $editTour = EditTourData::fromTour($tour)->toArray();

        // A driver-management edit (feature 025) carries where to return once re-optimized.
        $returnDriverId = request()->integer('return_to_driver');
        if ($returnDriverId > 0) {
            $editTour['returnTo'] = [
                'driverId' => $returnDriverId,
                'date' => request()->string('return_to_date')->value() ?: null,
            ];
        }

        return Inertia::render('tour/optimize', ['editTour' => $editTour]);
    }
}
