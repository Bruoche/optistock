<?php

namespace App\Http\Requests;

use App\Enums\DeliveryMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a road-geometry request: 2–10 ordered `[lat, lng]` stops and an
 * optional travel mode. When omitted the server uses the configured default
 * (`trucking`); the front sends the mode the tour was optimized with (003).
 */
class TourGeometryRequest extends FormRequest
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
            'stops.*' => ['required', 'array', 'size:2'],
            'stops.*.0' => ['required', 'numeric', 'between:-90,90'],
            'stops.*.1' => ['required', 'numeric', 'between:-180,180'],
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
            'stops.min' => 'At least two stops are required to trace a tour.',
            'stops.max' => 'A maximum of 10 stops can be traced at once.',
            'stops.*.size' => 'Each stop must be a [latitude, longitude] pair.',
            'stops.*.0.between' => 'Latitude must be between -90 and 90.',
            'stops.*.1.between' => 'Longitude must be between -180 and 180.',
            'mode' => 'Mode must be one of: trucking, driving, walking.',
        ];
    }
}
