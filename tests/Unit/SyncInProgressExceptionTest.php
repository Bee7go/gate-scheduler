<?php

namespace Tests\Unit;

use App\Exceptions\SyncInProgressException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SyncInProgressExceptionTest extends TestCase
{
    public function test_carries_a_default_message(): void
    {
        $exception = new SyncInProgressException;

        $this->assertSame('A sync is already in progress. Please try again later.', $exception->getMessage());
    }

    public function test_accepts_a_custom_message(): void
    {
        $exception = new SyncInProgressException('Hold on.');

        $this->assertSame('Hold on.', $exception->getMessage());
    }

    public function test_is_a_runtime_exception(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new SyncInProgressException);
    }
}
