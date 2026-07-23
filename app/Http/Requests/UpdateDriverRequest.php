<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a driver-detail edit (feature 025): a non-empty name, an existing warehouse,
 * at least one valid delivery mode, and an optional replacement image.
 */
class UpdateDriverRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'modes' => ['required', 'array', 'min:1'],
            'modes.*' => [Rule::enum(DeliveryModeEnum::class)],
            'image' => ['nullable', 'image', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'A driver name is required.',
            'modes.required' => 'Select at least one delivery mode.',
            'modes.min' => 'Select at least one delivery mode.',
            'warehouse_id.exists' => 'Choose an existing warehouse.',
        ];
    }
}
