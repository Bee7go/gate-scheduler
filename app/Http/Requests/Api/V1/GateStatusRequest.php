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
            /**
             * Point in time to evaluate gate status at. Defaults to now.
             *
             * @example 2026-01-01T12:00:00Z
             */
            'at' => ['sometimes', 'date'],
            /**
             * Only the status of this gate.
             *
             * @example A12
             */
            'gate_code' => ['sometimes', 'string', 'max:10'],
        ];
    }
}
