<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAllocationsRequest extends FormRequest
{
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
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page'     => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
