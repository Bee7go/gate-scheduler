<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class GateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'at'        => ['sometimes', 'date'],
            'gate_code' => ['sometimes', 'string', 'max:10'],
        ];
    }
}
