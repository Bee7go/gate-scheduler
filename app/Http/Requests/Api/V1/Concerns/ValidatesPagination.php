<?php

namespace App\Http\Requests\Api\V1\Concerns;

trait ValidatesPagination
{
    /**
     * Shared validation rules for paginated list endpoints.
     *
     * @return array<string, array<int, string>>
     */
    protected function paginationRules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
