<?php

namespace Tests\Feature;

use Tests\TestCase;

class DeploymentHealthCheckTest extends TestCase
{
    public function test_platform_health_check_is_publicly_reachable(): void
    {
        $this->get('/up')->assertOk();
    }
}
