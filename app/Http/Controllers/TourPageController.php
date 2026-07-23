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

        $returnDriverId = request()->integer('return_to_driver');
        $fromDriverPage = $returnDriverId > 0;

        if ($tour->isAssigned() && ! $fromDriverPage) {
            return redirect()->route('tour.optimize.page');
        }

        $editTour = EditTourData::fromTour($tour)->toArray();

        if ($fromDriverPage) {
            $editTour['returnTo'] = [
                'driverId' => $returnDriverId,
                'date' => request()->string('return_to_date')->value() ?: null,
            ];
        }

        return Inertia::render('tour/optimize', ['editTour' => $editTour]);
    }
}
