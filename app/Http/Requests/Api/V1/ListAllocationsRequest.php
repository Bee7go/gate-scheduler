<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAllocationsRequest extends FormRequest
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
             * Only allocations on this gate.
             *
             * @example A12
             */
            'gate_code' => ['sometimes', 'string', 'max:10'],
            /**
             * Only allocations starting at or after this date or date-time.
             *
             * @example 2026-01-01T06:00:00Z
             */
            'occupied_from' => [
                'sometimes', 'date',
                Rule::when($this->filled('occupied_until'), 'before_or_equal:occupied_until'),
            ],
            /**
             * Only allocations ending at or before this date or date-time. Must not precede `occupied_from`.
             *
             * @example 2026-01-01T22:00:00Z
             */
            'occupied_until' => [
                'sometimes', 'date',
                Rule::when($this->filled('occupied_from'), 'after_or_equal:occupied_from'),
            ],
            ...$this->paginationRules(),
        ];
    }
}
