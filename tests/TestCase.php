<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fail loudly if a test triggers an HTTP request that was not faked,
        // instead of silently hitting the live OpenSky API.
        Http::preventStrayRequests();
    }
}
