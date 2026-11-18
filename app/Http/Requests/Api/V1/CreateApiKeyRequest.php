<?php

namespace App\Http\Requests\Api\V1;

use App\Models\ApiKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('name') && is_string($this->name)) {
            $normalized['name'] = trim($this->name);
        }

        if ($this->has('description') && is_string($this->description)) {
            $normalized['description'] = trim($this->description);
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            /**
             * Scopes to restrict the key to. Omit for an unrestricted key.
             * `read` covers the GET data endpoints, `write` creating gate
             * unavailabilities, `sync` triggering flight syncs.
             *
             * @example ["read", "write"]
             */
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(ApiKey::SCOPES)],
        ];
    }

    public function messages(): array
    {
        return [
            'scopes.*.in' => 'Each scope must be one of: '.implode(', ', ApiKey::SCOPES).'.',
        ];
    }
}
