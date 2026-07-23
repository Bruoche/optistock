<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Validates a day-reorder (feature 025): the date, the full ordered tour-id list, and an optional force flag. */
class ReorderToursRequest extends FormRequest
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
            'date' => ['required', 'date'],
            'tour_ids' => ['required', 'array', 'min:1'],
            'tour_ids.*' => ['integer'],
            'force' => ['boolean'],
        ];
    }
}
