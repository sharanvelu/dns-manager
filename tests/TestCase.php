<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Tests must never talk to real provider APIs.
        Http::preventStrayRequests();

        // Connectors pace themselves (Pi-hole's post-CNAME restart cooldown)
        // — record sleeps instead of actually waiting.
        Sleep::fake();
    }
}
