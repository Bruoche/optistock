<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the available-drivers query: a required `mode` (one of the
 * App\Enums\DeliveryMode values) and a required `date` (the tour's day). The
 * weekday is deduced server-side from `date`; no weekday is accepted from the client.
 */
class AvailableDriversRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::enum(DeliveryModeEnum::class)],
            'date' => ['required', 'date'],
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
        ];
    }
}
