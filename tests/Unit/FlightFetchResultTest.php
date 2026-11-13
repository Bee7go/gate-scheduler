<?php

namespace Tests\Unit;

use App\Services\Flights\FlightFetchResult;
use PHPUnit\Framework\TestCase;

class FlightFetchResultTest extends TestCase
{
    public function test_live_result_is_available(): void
    {
        $result = new FlightFetchResult([['icao24' => 'abc123']], FlightFetchResult::SOURCE_LIVE);

        $this->assertTrue($result->isAvailable());
        $this->assertSame(FlightFetchResult::SOURCE_LIVE, $result->source);
        $this->assertNull($result->reason);
    }

    public function test_fallback_result_is_available(): void
    {
        $result = new FlightFetchResult([], FlightFetchResult::SOURCE_FALLBACK, 'circuit_breaker_open');

        $this->assertTrue($result->isAvailable());
        $this->assertSame('circuit_breaker_open', $result->reason);
    }

    public function test_unavailable_result_is_not_available(): void
    {
        $result = new FlightFetchResult([], FlightFetchResult::SOURCE_UNAVAILABLE, 'invalid_airport');

        $this->assertFalse($result->isAvailable());
        $this->assertSame([], $result->flights);
        $this->assertSame('invalid_airport', $result->reason);
    }
}
