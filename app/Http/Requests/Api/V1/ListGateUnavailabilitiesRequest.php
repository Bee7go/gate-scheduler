<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesPagination;
use Illuminate\Foundation\Http\FormRequest;

class ListGateUnavailabilitiesRequest extends FormRequest
{
    use ValidatesPagination;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /**
             * Only unavailabilities for this gate.
             *
             * @example 3
             */
            'gate_id' => ['sometimes', 'integer', 'min:1'],
            /**
             * Only windows that end on or after this date or date-time.
             *
             * @example 2026-01-01
             */
            'from' => ['sometimes', 'date'],
            /**
             * Only windows that start on or before this date or date-time. Must be on or after `from`.
             *
             * @example 2026-01-31
             */
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            ...$this->paginationRules(),
        ];
    }
}
