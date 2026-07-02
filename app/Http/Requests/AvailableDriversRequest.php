<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use App\Models\Tour;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The weekday is deduced server-side from `date`; no weekday is accepted from the client.
 * An unknown or non-owned `tour` surfaces as 404, never confirming a foreign tour id.
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
            return true;
        }

        $tour = Tour::find((int) $tourId);

        return $tour !== null && $tour->user_id === $this->user()->id;
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
