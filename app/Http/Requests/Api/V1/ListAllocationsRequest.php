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
            'gate_code' => ['sometimes', 'string', 'max:10'],
            'occupied_from' => [
                'sometimes', 'date',
                Rule::when($this->filled('occupied_until'), 'before_or_equal:occupied_until'),
            ],
            'occupied_until' => [
                'sometimes', 'date',
                Rule::when($this->filled('occupied_from'), 'after_or_equal:occupied_from'),
            ],
            ...$this->paginationRules(),
        ];
    }
}
