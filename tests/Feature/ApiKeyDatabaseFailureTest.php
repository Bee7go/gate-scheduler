<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ApiKeyDatabaseFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_failure_does_not_bypass_api_key_protection(): void
    {
        $key = 'test-api-key';
        ApiKey::create(['name' => 'Test Client', 'key' => hash('sha256', $key)]);

        $pdo = DB::connection('sqlite')->getPdo();
        DB::purge('sqlite');
        config(['database.connections.sqlite.database' => '/nonexistent/path/db.sqlite']);
        DB::reconnect('sqlite');

        $response = $this->getJson('/api/v1/statistics', ['X-Api-Key' => $key]);

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        DB::purge('sqlite');
        config(['database.connections.sqlite.database' => ':memory:']);
        DB::connection('sqlite')->setPdo($pdo);
        RefreshDatabaseState::$inMemoryConnections['sqlite'] = $pdo;

        $response->assertStatus(503)
            ->assertJson(['message' => 'Service unavailable.']);
    }
}
