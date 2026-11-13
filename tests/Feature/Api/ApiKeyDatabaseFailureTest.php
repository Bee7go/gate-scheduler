<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class ApiKeyDatabaseFailureTest extends TestCase
{
    public function test_database_failure_does_not_bypass_api_key_protection(): void
    {
        $originalDefault = config('database.default');

        // Route the request through a connection that cannot be established,
        // without touching the suite's real connection or its transaction state.
        config([
            'database.connections.broken' => [
                'driver' => 'sqlite',
                'database' => '/nonexistent/path/db.sqlite',
                'foreign_key_constraints' => false,
            ],
            'database.default' => 'broken',
        ]);

        $response = $this->getJson('/api/v1/statistics', ['X-Api-Key' => 'any-key']);

        config(['database.default' => $originalDefault]);

        $response->assertStatus(503)
            ->assertJson(['message' => 'Service unavailable.']);
    }
}
