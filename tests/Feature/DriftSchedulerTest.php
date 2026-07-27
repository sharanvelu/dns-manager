<?php

declare(strict_types = 1);

use App\Models\Provider;
use App\Jobs\CheckProviderDrift;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('dns:check-drift queues a job per enabled provider', function () {
    $enabled = Provider::factory()->cloudflare()->create();
    Provider::factory()->pihole()->create();
    $disabled = Provider::factory()->cloudflare()->disabled()->create();

    $this->artisan('dns:check-drift')
        ->expectsOutputToContain('Queued drift check for 2 provider(s)')
        ->assertSuccessful();

    Queue::assertPushed(CheckProviderDrift::class, 2);
    Queue::assertNotPushed(CheckProviderDrift::class, fn ($job) => $job->providerId === $disabled->id);
});

test('dns:check-drift can target a single provider', function () {
    $target = Provider::factory()->cloudflare()->create();
    Provider::factory()->pihole()->create();

    $this->artisan('dns:check-drift', ['--provider' => $target->id])->assertSuccessful();

    Queue::assertPushed(CheckProviderDrift::class, 1);
    Queue::assertPushed(CheckProviderDrift::class, fn ($job) => $job->providerId === $target->id);
});

test('the drift-check webhook is disabled without a configured token', function () {
    config(['dns.trigger_token' => null]);

    $this->postJson('/api/hooks/drift-check')->assertNotFound();
});

test('the drift-check webhook rejects a missing or wrong token', function () {
    config(['dns.trigger_token' => 'secret-token']);
    Provider::factory()->cloudflare()->create();

    $this->postJson('/api/hooks/drift-check')->assertUnauthorized();

    $this->withHeader('Authorization', 'Bearer wrong')
        ->postJson('/api/hooks/drift-check')
        ->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('the drift-check webhook queues checks for enabled providers', function () {
    config(['dns.trigger_token' => 'secret-token']);

    $cf = Provider::factory()->cloudflare()->create(['name' => 'CF']);
    Provider::factory()->pihole()->create(['name' => 'Pi', 'enabled' => false]);

    $this->withHeader('Authorization', 'Bearer secret-token')
        ->postJson('/api/hooks/drift-check')
        ->assertOk()
        ->assertJson(['queued' => 1, 'providers' => ['CF']]);

    Queue::assertPushed(CheckProviderDrift::class, fn ($job) => $job->providerId === $cf->id);
});

test('the drift-check webhook can target one provider and needs no session or csrf', function () {
    config(['dns.trigger_token' => 'secret-token']);

    $target = Provider::factory()->cloudflare()->create();
    Provider::factory()->pihole()->create();

    // Plain POST (no CSRF token, no authenticated user) must work.
    $this->post('/api/hooks/drift-check', ['provider_id' => $target->id], [
        'Authorization' => 'Bearer secret-token',
        'Accept' => 'application/json',
    ])->assertOk()->assertJson(['queued' => 1]);

    Queue::assertPushed(CheckProviderDrift::class, 1);
});
