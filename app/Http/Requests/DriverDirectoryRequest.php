<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMode as DeliveryModeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Criteria for the drivers directory (feature 027). Every criterion is optional; an omitted or
 * blank one imposes no restriction. An unknown warehouse or invalid mode is rejected (422), never
 * silently dropped.
 */
class DriverDirectoryRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'modes' => ['nullable', 'array'],
            'modes.*' => [Rule::enum(DeliveryModeEnum::class)],
            'warehouse' => ['nullable', 'integer', 'exists:warehouses,id'],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function modes(): array
    {
        return $this->validated('modes') ?? [];
    }
}
