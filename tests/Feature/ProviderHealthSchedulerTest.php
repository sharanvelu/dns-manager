<?php

declare(strict_types = 1);

use App\Models\Provider;
use App\Jobs\CheckProviderHealth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('dns:check-provider-health queues a job per enabled provider', function () {
    Queue::fake();

    $enabled = Provider::factory()->cloudflare()->create();
    Provider::factory()->pihole()->create();
    $disabled = Provider::factory()->cloudflare()->disabled()->create();

    $this->artisan('dns:check-provider-health')
        ->expectsOutputToContain('Queued health check for 2 provider(s)')
        ->assertSuccessful();

    Queue::assertPushed(CheckProviderHealth::class, 2);
    Queue::assertNotPushed(CheckProviderHealth::class, fn ($job) => $job->providerId === $disabled->id);
});

test('dns:check-provider-health can target a single provider', function () {
    Queue::fake();

    $target = Provider::factory()->cloudflare()->create();
    Provider::factory()->pihole()->create();

    $this->artisan('dns:check-provider-health', ['--provider' => $target->id])->assertSuccessful();

    Queue::assertPushed(CheckProviderHealth::class, 1);
    Queue::assertPushed(CheckProviderHealth::class, fn ($job) => $job->providerId === $target->id);
});

test('the provider-health-check webhook is disabled without a configured token', function () {
    config(['dns.trigger_token' => null]);

    $this->postJson('/api/hooks/provider-health-check')->assertNotFound();
});

test('the provider-health-check webhook rejects a missing or wrong token', function () {
    Queue::fake();
    config(['dns.trigger_token' => 'secret-token']);
    Provider::factory()->cloudflare()->create();

    $this->postJson('/api/hooks/provider-health-check')->assertUnauthorized();

    $this->withHeader('Authorization', 'Bearer wrong')
        ->postJson('/api/hooks/provider-health-check')
        ->assertUnauthorized();

    Queue::assertNothingPushed();
});

test('the provider-health-check webhook queues checks for enabled providers', function () {
    Queue::fake();
    config(['dns.trigger_token' => 'secret-token']);

    $cf = Provider::factory()->cloudflare()->create(['name' => 'CF']);
    Provider::factory()->pihole()->create(['name' => 'Pi', 'enabled' => false]);

    $this->withHeader('Authorization', 'Bearer secret-token')
        ->postJson('/api/hooks/provider-health-check')
        ->assertOk()
        ->assertJson(['queued' => 1, 'providers' => ['CF']]);

    Queue::assertPushed(CheckProviderHealth::class, fn ($job) => $job->providerId === $cf->id);
});

test('the provider-health-check webhook can target one provider and needs no session or csrf', function () {
    Queue::fake();
    config(['dns.trigger_token' => 'secret-token']);

    $target = Provider::factory()->cloudflare()->create();
    Provider::factory()->pihole()->create();

    // Plain POST (no CSRF token, no authenticated user) must work.
    $this->post('/api/hooks/provider-health-check', ['provider_id' => $target->id], [
        'Authorization' => 'Bearer secret-token',
        'Accept' => 'application/json',
    ])->assertOk()->assertJson(['queued' => 1]);

    Queue::assertPushed(CheckProviderHealth::class, 1);
});

test('health check marks the provider healthy when the connection succeeds', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones*' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [],
            'result' => [['id' => 'zone-1', 'name' => 'example.com', 'status' => 'active']],
            'result_info' => ['total_count' => 1],
        ]),
    ]);

    $provider = Provider::factory()->cloudflare()->create([
        'health_status' => 'error',
        'health_message' => 'previous failure',
    ]);

    (new CheckProviderHealth($provider->id))->handle();

    $provider->refresh();

    expect($provider->health_status->value)->toBe('ok')
        ->and($provider->health_message)->toBeNull()
        ->and($provider->last_checked_at)->not->toBeNull();

    $this->assertDatabaseHas('sync_logs', [
        'provider_id' => $provider->id,
        'action' => 'provider-health-check',
        'status' => 'success',
    ]);
});

test('health check marks the provider unhealthy when the connection fails', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 9109, 'message' => 'Invalid access token']], 'result' => null], 401)]);

    $provider = Provider::factory()->cloudflare()->create();

    (new CheckProviderHealth($provider->id))->handle();

    expect($provider->fresh()->health_status->value)->toBe('error')
        ->and($provider->fresh()->health_message)->toContain('9109');

    $this->assertDatabaseHas('sync_logs', [
        'provider_id' => $provider->id,
        'action' => 'provider-health-check',
        'status' => 'error',
    ]);
});

test('health check skips disabled or deleted providers', function () {
    Http::fake();

    $disabled = Provider::factory()->cloudflare()->disabled()->create([
        'health_status' => 'unchecked',
    ]);

    (new CheckProviderHealth($disabled->id))->handle();
    (new CheckProviderHealth($disabled->id + 999))->handle();

    expect($disabled->fresh()->health_status->value)->toBe('unchecked');
    Http::assertNothingSent();
});
