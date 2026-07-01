<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a tour-optimization request: 2–10 stops, each a `{lat, lng, duration_s}`
 * object (in-range coordinate + per-stop delivery duration in seconds, 007), plus
 * an optional travel mode and loop shape. When omitted the server falls back to the
 * configured default (`trucking`) and a closed loop.
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
            'stops' => ['required', 'array', 'min:2', 'max:10'],
            'stops.*' => ['required', 'array'],
            'stops.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'stops.*.duration_s' => ['required', 'integer', 'min:0'],
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
            'stops.min' => 'At least two stops are required for tour optimization.',
            'stops.max' => 'A maximum of 10 stops can be optimized at once.',
            'stops.*.lat.between' => 'Latitude must be between -90 and 90.',
            'stops.*.lng.between' => 'Longitude must be between -180 and 180.',
            'stops.*.duration_s' => 'Each stop needs a delivery duration in seconds.',
            'mode' => 'Mode must be one of: trucking, driving, walking.',
        ];
    }
}
