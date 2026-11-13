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
            'from' => ['required', 'date'],
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
