<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMode;
use App\Models\Tour;
use App\Repositories\TourRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Validates a tour-optimization request: 2–10 stops, each a `{lat, lng, duration_s}`
 * object (in-range coordinate + per-stop delivery duration in seconds, 007), plus
 * an optional travel mode and loop shape. When omitted the server falls back to the
 * configured default (`trucking`) and a closed loop.
 *
 * An optional `tour_id` (feature 020) switches persistence from create to update: it
 * must reference the caller's own, still-unassigned tour. A foreign/absent id is a 404
 * (never confirm a foreign id exists, as in {@see AssignTourRequest}); an assigned tour
 * is a 422 (past attribution, not editable).
 */
class OptimizeTourRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $tourId = $this->input('tour_id');
        // A non-numeric tour_id is a validation error (422), not an authorization one — let the rule report it.
        if ($tourId === null || ! is_numeric($tourId)) {
            return true;
        }

        return $this->ownedTour((int) $tourId) !== null;
    }

    protected function failedAuthorization(): void
    {
        throw new NotFoundHttpException;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'stops' => ['required', 'array', 'min:2', 'max:10'],
            'stops.*' => ['required', 'array'],
            'stops.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'stops.*.duration_s' => ['required', 'integer', 'min:0'],
            'mode' => ['sometimes', Rule::enum(DeliveryMode::class)],
            'loop' => ['sometimes', 'boolean'],
            'tour_id' => ['sometimes', 'integer', $this->unassignedTourRule()],
        ];
    }

    /**
     * Ownership is settled in {@see authorize()};
     */
    private function unassignedTourRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            if ($this->integer('return_to_driver') > 0) {
                return;
            }

            $tour = $this->ownedTour((int) $value);

            if ($tour !== null && $tour->drivers()->exists()) {
                $fail('An assigned tour cannot be edited.');
            }
        };
    }

    /** The caller's own tour for this id, or null (ownership settles the 404 in authorize). */
    private function ownedTour(int $tourId): ?Tour
    {
        return app(TourRepository::class)->findOwnedTour($tourId, (int) $this->user()->id);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stops.min' => 'At least two stops are required for tour optimization.',
            'stops.max' => 'A maximum of 10 stops can be optimized at once.',
            'stops.*.lat.between' => 'Latitude must be between -90 and 90.',
            'stops.*.lng.between' => 'Longitude must be between -180 and 180.',
            'stops.*.duration_s' => 'Each stop needs a delivery duration in seconds.',
            'mode' => 'Mode must be one of: trucking, driving, walking.',
        ];
    }
}
