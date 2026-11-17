<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

trait RespondsWithPagination
{
    /**
     * Shared "meta" block for every paginated list endpoint:
     * {current_page, per_page, last_page, total}.
     *
     * @return array{current_page: int, per_page: int, last_page: int, total: int}
     */
    protected function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }
}
