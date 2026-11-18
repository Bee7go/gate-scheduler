<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function createKey(string $plain, ?int $perMinute): void
    {
        ApiKey::create([
            'name' => 'limited key',
            'key' => hash('sha256', $plain),
            'rate_limit_per_minute' => $perMinute,
        ]);
    }

    public function test_responses_carry_rate_limit_headers(): void
    {
        $this->createKey('limited', 5);

        $response = $this->getJson('/api/v1/allocations', ['X-Api-Key' => 'limited']);

        $response->assertStatus(200);
        $this->assertSame('5', $response->headers->get('X-RateLimit-Limit'));
        $this->assertSame('4', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_exceeding_the_per_key_limit_returns_429_with_retry_after(): void
    {
        $this->createKey('tiny', 2);

        $this->getJson('/api/v1/allocations', ['X-Api-Key' => 'tiny'])->assertStatus(200);
        $this->getJson('/api/v1/allocations', ['X-Api-Key' => 'tiny'])->assertStatus(200);

        $response = $this->getJson('/api/v1/allocations', ['X-Api-Key' => 'tiny']);

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_keys_have_separate_buckets(): void
    {
        $this->createKey('first', 1);
        $this->createKey('second', 1);

        $this->getJson('/api/v1/allocations', ['X-Api-Key' => 'first'])->assertStatus(200);
        $this->getJson('/api/v1/allocations', ['X-Api-Key' => 'first'])->assertStatus(429);

        // A different key is unaffected by the first key's exhausted bucket.
        $this->getJson('/api/v1/allocations', ['X-Api-Key' => 'second'])->assertStatus(200);
    }

    public function test_keys_without_their_own_limit_use_the_configured_default(): void
    {
        config(['services.auth.key_rate_limit_per_minute' => 3]);
        $this->createKey('default-limited', null);

        $response = $this->getJson('/api/v1/allocations', ['X-Api-Key' => 'default-limited']);

        $response->assertStatus(200);
        $this->assertSame('3', $response->headers->get('X-RateLimit-Limit'));
    }
}
