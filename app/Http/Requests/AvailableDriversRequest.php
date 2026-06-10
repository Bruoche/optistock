<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the available-drivers query: a required `mode` that must be one of
 * the App\Enums\DeliveryMode values (trucking, driving, walking).
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
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mode' => 'Mode must be one of: trucking, driving, walking.',
        ];
    }
}
