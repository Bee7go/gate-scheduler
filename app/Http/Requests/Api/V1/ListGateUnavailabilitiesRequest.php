<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListGateUnavailabilitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'gate_id' => ['sometimes', 'integer', 'min:1'],
            'from'    => ['sometimes', 'date'],
            'to'      => ['sometimes', 'date', 'after_or_equal:from'],
        ];
    }
}
