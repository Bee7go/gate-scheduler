<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Gate;
use App\Models\GateUnavailability;
use App\Models\IdempotencyKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private string $plainKey = 'idempotency-test-key';

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();

        ApiKey::create(['name' => 'Test Client', 'key' => hash('sha256', $this->plainKey)]);
        $gate = Gate::create(['code' => 'A1']);

        $this->payload = [
            'gate_id' => $gate->id,
            'start_at' => '2026-12-01T08:00:00Z',
            'end_at' => '2026-12-01T10:00:00Z',
            'reason' => 'maintenance',
        ];
    }

    private function postUnavailability(array $payload, array $headers = []): TestResponse
    {
        return $this->postJson(
            '/api/v1/gates/unavailabilities',
            $payload,
            array_merge(['X-Api-Key' => $this->plainKey], $headers)
        );
    }

    public function test_requests_without_the_header_behave_normally(): void
    {
        $this->postUnavailability($this->payload)->assertStatus(201);
        $this->postUnavailability($this->payload)->assertStatus(201);

        $this->assertSame(2, GateUnavailability::count());
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_retrying_with_the_same_key_replays_the_response_instead_of_repeating(): void
    {
        $first = $this->postUnavailability($this->payload, ['Idempotency-Key' => 'retry-abc']);
        $first->assertStatus(201);

        $replay = $this->postUnavailability($this->payload, ['Idempotency-Key' => 'retry-abc']);

        $replay->assertStatus(201);
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, GateUnavailability::count());
    }

    public function test_reusing_a_key_with_a_different_request_conflicts(): void
    {
        $this->postUnavailability($this->payload, ['Idempotency-Key' => 'retry-abc'])->assertStatus(201);

        $different = array_merge($this->payload, ['reason' => 'cleaning']);

        $this->postUnavailability($different, ['Idempotency-Key' => 'retry-abc'])
            ->assertStatus(409)
            ->assertJson([
                'code' => 'idempotency_conflict',
                'message' => 'This Idempotency-Key was already used with a different request.',
            ]);

        $this->assertSame(1, GateUnavailability::count());
    }

    public function test_a_malformed_idempotency_key_is_rejected(): void
    {
        $this->postUnavailability($this->payload, ['Idempotency-Key' => 'spaces are not allowed'])
            ->assertStatus(422)
            ->assertJson(['message' => 'The request failed validation.'])
            ->assertJsonValidationErrors(['Idempotency-Key']);

        $this->assertSame(0, GateUnavailability::count());
    }

    public function test_validation_failures_are_replayed_too(): void
    {
        $invalid = array_merge($this->payload, ['end_at' => '2026-12-01T07:00:00Z']);

        $this->postUnavailability($invalid, ['Idempotency-Key' => 'invalid-window'])->assertStatus(422);
        $replay = $this->postUnavailability($invalid, ['Idempotency-Key' => 'invalid-window']);

        $replay->assertStatus(422);
        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
    }

    public function test_a_request_still_in_progress_conflicts_instead_of_running_twice(): void
    {
        // Simulate the reservation a concurrent in-flight request holds.
        IdempotencyKey::create([
            'credential' => 'api-key:'.ApiKey::first()->id,
            'idempotency_key' => 'in-flight',
            'request_hash' => hash('sha256', implode('|', [
                'POST', 'api/v1/gates/unavailabilities', '', json_encode($this->payload),
            ])),
        ]);

        $this->postUnavailability($this->payload, ['Idempotency-Key' => 'in-flight'])
            ->assertStatus(409)
            ->assertJson([
                'code' => 'idempotency_in_progress',
                'message' => 'A request with this Idempotency-Key is still in progress. Retry shortly.',
            ]);

        $this->assertSame(0, GateUnavailability::count());
    }

    public function test_recorded_responses_are_encrypted_at_rest(): void
    {
        $this->postUnavailability($this->payload, ['Idempotency-Key' => 'enc-check'])->assertStatus(201);

        $stored = IdempotencyKey::first();

        $this->assertSame(201, $stored->response_status);
        // The raw column must not expose the response content.
        $this->assertStringNotContainsString('maintenance', $stored->response_body);
    }

    public function test_recorded_api_key_creation_does_not_store_the_plain_key(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Idem Tester',
            'email' => 'idem@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(201);

        $token = $this->postJson('/api/v1/login', [
            'email' => 'idem@example.com',
            'password' => 'Password123',
        ])->json('data.access_token');

        $first = $this->postJson('/api/v1/api-keys', ['name' => 'replayable'], [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'key-create-1',
        ]);
        $first->assertStatus(201);
        $plainKey = $first->json('data.api_key');

        // The plain key is in the (encrypted) recorded response, never in clear.
        $this->assertStringNotContainsString($plainKey, IdempotencyKey::first()->response_body);

        // A retry replays the identical response, including the plain key.
        $replay = $this->postJson('/api/v1/api-keys', ['name' => 'replayable'], [
            'Authorization' => 'Bearer '.$token,
            'Idempotency-Key' => 'key-create-1',
        ]);
        $replay->assertStatus(201);
        $this->assertSame($plainKey, $replay->json('data.api_key'));
        $this->assertSame(1, ApiKey::where('name', 'replayable')->count());
    }

    public function test_a_different_query_string_counts_as_a_different_request(): void
    {
        $this->postUnavailability($this->payload, ['Idempotency-Key' => 'query-check'])->assertStatus(201);

        $this->postJson('/api/v1/gates/unavailabilities?dry_run=1', $this->payload, [
            'X-Api-Key' => $this->plainKey,
            'Idempotency-Key' => 'query-check',
        ])->assertStatus(409);
    }

    public function test_expired_records_are_prunable(): void
    {
        $this->postUnavailability($this->payload, ['Idempotency-Key' => 'old-one'])->assertStatus(201);
        IdempotencyKey::query()->update(['created_at' => now()->subHours(25)]);

        $this->postUnavailability($this->payload, ['Idempotency-Key' => 'fresh-one'])->assertStatus(201);

        $this->assertSame(1, (new IdempotencyKey)->prunable()->count());
    }

    public function test_keys_are_scoped_per_credential(): void
    {
        ApiKey::create(['name' => 'Other Client', 'key' => hash('sha256', 'other-key')]);

        $this->postUnavailability($this->payload, ['Idempotency-Key' => 'shared-value'])->assertStatus(201);

        // The same idempotency key under a different credential is a fresh request.
        $this->postJson('/api/v1/gates/unavailabilities', $this->payload, [
            'X-Api-Key' => 'other-key',
            'Idempotency-Key' => 'shared-value',
        ])->assertStatus(201);

        $this->assertSame(2, GateUnavailability::count());
    }
}
