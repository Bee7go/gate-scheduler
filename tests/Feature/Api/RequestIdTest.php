<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequestIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_response_carries_a_request_id(): void
    {
        $response = $this->getJson('/api/v1/allocations'); // even a 401

        $response->assertStatus(401);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $response->headers->get('X-Request-Id')
        );
    }

    public function test_a_well_formed_client_supplied_id_is_echoed_back(): void
    {
        $response = $this->getJson('/api/v1/allocations', ['X-Request-Id' => 'client-trace-0042']);

        $this->assertSame('client-trace-0042', $response->headers->get('X-Request-Id'));
    }

    public function test_a_malformed_client_supplied_id_is_replaced(): void
    {
        $response = $this->getJson('/api/v1/allocations', ['X-Request-Id' => 'bad id with spaces & symbols!']);

        $this->assertNotSame('bad id with spaces & symbols!', $response->headers->get('X-Request-Id'));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f-]{36}$/',
            $response->headers->get('X-Request-Id')
        );
    }
}
