<?php

namespace App\Http\Requests;

/**
 * Validates a force-tour request (feature 024): the optimization fallback used when the
 * routing API is unavailable. It reuses every {@see OptimizeTourRequest} rule (2–10 stops,
 * in-range coordinates, per-stop duration, mode, loop, owned+unassigned `tour_id`) and adds
 * the one field the dead API can no longer supply: the tour's total drive duration, entered
 * by hand. Only a valid positive duration (up to a 24 h ceiling) is accepted — never zero.
 */
class ForceTourRequest extends OptimizeTourRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'travel_duration_s' => ['required', 'integer', 'min:1', 'max:86400'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'travel_duration_s.required' => 'A tour duration is required to force the tour.',
            'travel_duration_s.min' => 'The tour duration must be a positive number of seconds.',
            'travel_duration_s.max' => 'The tour duration cannot exceed 24 hours.',
        ];
    }
}
