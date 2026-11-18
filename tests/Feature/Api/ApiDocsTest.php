<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    public function test_interactive_docs_page_is_publicly_available(): void
    {
        $this->get('/docs/api')
            ->assertOk()
            ->assertSee('Airport Gate Scheduler API');
    }

    public function test_openapi_document_describes_the_v1_api(): void
    {
        $response = $this->get('/docs/api.json')->assertOk();

        $document = $response->json();

        $this->assertSame(config('scramble.info.version'), $document['info']['version']);
        $this->assertArrayHasKey('apiKey', $document['components']['securitySchemes']);
        $this->assertArrayHasKey('bearerToken', $document['components']['securitySchemes']);

        foreach (['/login', '/register', '/api-keys', '/allocations', '/gates/status', '/gates/unavailabilities', '/statistics', '/system/sync-now', '/system/health', '/system/status'] as $path) {
            $this->assertArrayHasKey($path, $document['paths'], "Missing {$path} in the OpenAPI document.");
        }
    }
}
