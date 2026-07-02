<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Models\Tour;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Validates the available-drivers query: a required `mode` (one of the
 * App\Enums\DeliveryMode values), a required `date` (the tour's day), and the
 * persisted `tour` whose chained workday is projected (feature 013). The weekday is
 * deduced server-side from `date`; no weekday is accepted from the client.
 *
 * Ownership is authorized here: a `tour` that is unknown or not the requesting user's
 * surfaces as 404 (never confirm a foreign tour id), mirroring the assignment guard.
 */
class AvailableDriversRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $tourId = $this->input('tour');
        if (! is_numeric($tourId)) {
            return true; // Missing/non-numeric tour → let validation return 422.
        }

        $tour = Tour::find((int) $tourId);

        return $tour !== null && $tour->user_id === $this->user()->id;
    }

    protected function failedAuthorization(): void
    {
        // Unknown or non-owned tour → 404, not 403: never confirm a foreign tour id.
        throw new NotFoundHttpException;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(DeliveryModeEnum::class)],
            'date' => ['required', 'date'],
            'tour' => ['required', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode' => 'Mode must be one of: trucking, driving, walking.',
            'date' => 'A valid tour date is required.',
            'tour' => 'A tour is required to project the working day.',
        ];
    }
}
