<?php

namespace Tests\Unit;

use App\Http\Requests\Api\V1\Concerns\ValidatesPagination;
use PHPUnit\Framework\TestCase;

class ValidatesPaginationTest extends TestCase
{
    private object $trait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->trait = new class
        {
            use ValidatesPagination;

            public function rules(): array
            {
                return $this->paginationRules();
            }
        };
    }

    public function test_exposes_rules_for_per_page_and_page(): void
    {
        $rules = $this->trait->rules();

        $this->assertSame(['per_page', 'page'], array_keys($rules));
    }

    public function test_per_page_is_bounded_between_1_and_100(): void
    {
        $rules = $this->trait->rules();

        $this->assertSame(['sometimes', 'integer', 'min:1', 'max:100'], $rules['per_page']);
    }

    public function test_page_must_be_a_positive_integer(): void
    {
        $rules = $this->trait->rules();

        $this->assertSame(['sometimes', 'integer', 'min:1'], $rules['page']);
    }
}
