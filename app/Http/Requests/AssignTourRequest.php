<?php

namespace App\Http\Requests;

use App\Enums\WeekDay as WeekDayEnum;
use App\Models\Driver;
use App\Models\Tour;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Validates a tour-assignment request. Ownership is authorized here: a tour that
 * is not the requesting user's surfaces as 404 (a foreign tour id is not confirmed
 * to exist). The driver must exist and be eligible for the bound tour — supporting
 * its delivery mode AND scheduled on the date's weekday — re-checked server-side so
 * the client-filtered list is never trusted (006/011).
 */
class AssignTourRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tour = $this->route('tour');

        return $this->user() !== null
            && $tour instanceof Tour
            && $tour->user_id === $this->user()->id;
    }

    protected function failedAuthorization(): void
    {
        // Non-owned tour → 404, not 403: never confirm a foreign tour id exists.
        throw new NotFoundHttpException;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'driver_id' => ['bail', 'required', 'integer', $this->eligibleDriverRule()],
            'date' => ['required', 'date'],
            'start_index' => ['bail', 'required', 'integer', $this->legalStartRule()],
        ];
    }

    private function legalStartRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            /** @var Tour $tour */
            $tour = $this->route('tour');

            if (! $tour->startCandidates()->contains('position', (int) $value)) {
                $fail('The selected start is not valid for this tour.');
            }
        };
    }

    /**
     * A driver must exist and be eligible for the bound tour: support its mode and be
     * scheduled on the assignment date's weekday.
     */
    private function eligibleDriverRule(): callable
    {
        return function (string $attribute, mixed $value, callable $fail): void {
            $date = $this->input('date');
            if (! is_string($date) || strtotime($date) === false) {
                return; // the `date` rule reports the bad date on its own.
            }

            $driver = Driver::with(['deliveryModes', 'weekDays'])->find($value);
            if ($driver === null) {
                $fail('The selected driver does not exist.');

                return;
            }

            /** @var Tour $tour */
            $tour = $this->route('tour');
            $modeLabel = $tour->deliveryMode->label;
            $weekday = WeekDayEnum::fromDate(Carbon::parse($date))->value;

            $supportsMode = $driver->deliveryModes->contains('label', $modeLabel);
            $scheduled = $driver->weekDays->contains('label', $weekday);

            if (! $supportsMode || ! $scheduled) {
                $fail('The selected driver is not available for this tour.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date' => 'A valid assignment date is required.',
        ];
    }
}
