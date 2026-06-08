<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a tour-optimization request: 2–10 coordinate pairs, each a
 * `[lat, lng]` array with in-range numeric values, plus an optional travel
 * mode. When omitted the server falls back to the configured default
 * (`trucking`).
 */
class OptimizeTourRequest extends FormRequest
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
            'coordinates' => ['required', 'array', 'min:2', 'max:10'],
            'coordinates.*' => ['required', 'array', 'size:2'],
            'coordinates.*.0' => ['required', 'numeric', 'between:-90,90'],
            'coordinates.*.1' => ['required', 'numeric', 'between:-180,180'],
            'mode' => ['sometimes', Rule::enum(DeliveryMode::class)],
            'loop' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'coordinates.min' => 'At least two coordinates are required for tour optimization.',
            'coordinates.max' => 'A maximum of 10 coordinates can be optimized at once.',
            'coordinates.*.size' => 'Each coordinate must be a [latitude, longitude] pair.',
            'coordinates.*.0.between' => 'Latitude must be between -90 and 90.',
            'coordinates.*.1.between' => 'Longitude must be between -180 and 180.',
            'mode' => 'Mode must be one of: trucking, driving, walking.',
        ];
    }
}
