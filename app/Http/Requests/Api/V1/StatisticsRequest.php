<?php

namespace App\Http\Requests\Api\V1;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StatisticsRequest extends FormRequest
{
    /**
     * Upper bound for the statistics window; the per-hour peak scan grows
     * linearly with the range, so an unbounded range is a DoS vector.
     */
    public const MAX_RANGE_DAYS = 92;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Start of the reporting period (inclusive). Any date or date-time string, e.g. `2026-01-01` or `2026-01-01T06:00:00Z`.
             *
             * @example 2026-01-01
             */
            'from' => ['required', 'date'],
            /**
             * End of the reporting period (exclusive). Must be after `from` and at most 92 days later.
             *
             * @example 2026-01-31
             */
            'to' => ['required', 'date', 'after:from'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = Carbon::parse($this->input('from'));
            $to = Carbon::parse($this->input('to'));

            if ($from->diffInDays($to) > self::MAX_RANGE_DAYS) {
                $validator->errors()->add(
                    'to',
                    sprintf('The period may not exceed %d days.', self::MAX_RANGE_DAYS)
                );
            }
        });
    }
}
