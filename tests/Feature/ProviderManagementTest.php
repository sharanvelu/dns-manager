<?php

use App\Jobs\CheckProviderDrift;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();
});

test('providers index renders with connector descriptors', function () {
    Provider::factory()->cloudflare()->create();

    $this->get('/providers')->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('providers/index')
            ->has('providers', 1)
            ->has('connectors', 2)
            ->where('connectors.0.type', 'cloudflare')
            ->has('connectors.0.configSchema')
    );
});

test('a cloudflare provider can be created', function () {
    $this->post('/providers', [
        'name' => 'My Zone',
        'type' => 'cloudflare',
        'enabled' => true,
        'managed_record_types' => ['A', 'CNAME'],
        'config' => ['api_token' => 'tok-123', 'zone_id' => 'zone-1'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $provider = Provider::sole();
    expect($provider->config['api_token'])->toBe('tok-123');
    Queue::assertPushed(CheckProviderDrift::class);
});

test('unsupported record types are rejected', function () {
    $this->post('/providers', [
        'name' => 'Pi',
        'type' => 'pihole',
        'enabled' => true,
        'managed_record_types' => ['A', 'MX'],
        'config' => ['base_url' => 'https://pi.hole', 'app_password' => 'pw'],
    ])->assertSessionHasErrors('managed_record_types');
});

test('missing required config fields are rejected', function () {
    $this->post('/providers', [
        'name' => 'CF',
        'type' => 'cloudflare',
        'enabled' => true,
        'managed_record_types' => ['A'],
        'config' => ['zone_id' => 'zone-1'],
    ])->assertSessionHasErrors('config.api_token');
});

test('blank secrets on update keep the stored value', function () {
    $provider = Provider::factory()->cloudflare()->create([
        'config' => ['api_token' => 'original-secret', 'zone_id' => 'zone-1'],
    ]);

    $this->put("/providers/{$provider->id}", [
        'name' => 'Renamed',
        'type' => 'cloudflare',
        'enabled' => true,
        'managed_record_types' => ['A'],
        'config' => ['api_token' => '', 'zone_id' => 'zone-2'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $provider->refresh();
    expect($provider->name)->toBe('Renamed')
        ->and($provider->config['api_token'])->toBe('original-secret')
        ->and($provider->config['zone_id'])->toBe('zone-2');
});

test('secrets are never exposed on the providers page', function () {
    Provider::factory()->cloudflare()->create([
        'config' => ['api_token' => 'super-secret', 'zone_id' => 'zone-1'],
    ]);

    $this->get('/providers')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('providers.0.config.api_token', '')
            ->where('providers.0.config.zone_id', 'zone-1'))
        ->assertDontSee('super-secret');
});

test('test connection endpoint proxies the connector result', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
            'success' => true, 'errors' => [], 'result' => ['id' => 't', 'status' => 'active'],
        ]),
        'api.cloudflare.com/client/v4/zones/zone-1' => Http::response([
            'success' => true, 'errors' => [], 'result' => ['id' => 'zone-1', 'name' => 'example.com', 'status' => 'active'],
        ]),
    ]);

    $this->postJson('/providers/test', [
        'type' => 'cloudflare',
        'config' => ['api_token' => 'tok', 'zone_id' => 'zone-1'],
    ])->assertOk()->assertJson(['ok' => true])->assertJsonPath('details.zone', 'example.com');
});

test('test connection endpoint reuses stored secrets when blank', function () {
    $provider = Provider::factory()->pihole()->create([
        'config' => ['base_url' => 'https://pi.hole', 'app_password' => 'stored-pw', 'verify_tls' => false],
    ]);

    Http::fake([
        'pi.hole/api/auth' => Http::response(['session' => ['valid' => true, 'sid' => 's', 'csrf' => 'c', 'validity' => 300]]),
        'pi.hole/api/info/version' => Http::response(['version' => ['core' => ['local' => ['version' => 'v6.1']]]]),
    ]);

    $this->postJson('/providers/test', [
        'type' => 'pihole',
        'provider_id' => $provider->id,
        'config' => ['base_url' => 'https://pi.hole', 'app_password' => '', 'verify_tls' => false],
    ])->assertOk()->assertJson(['ok' => true]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/auth')
        && $request->method() === 'POST'
        && $request['password'] === 'stored-pw');
});

test('deleting a provider keeps dns entries', function () {
    $provider = Provider::factory()->cloudflare()->create();
    $entry = DnsEntry::factory()->create();
    $entry->syncStates()->create(['provider_id' => $provider->id, 'sync_status' => 'synced', 'external_id' => 'cf-1']);

    $this->delete("/providers/{$provider->id}")->assertRedirect();

    expect(Provider::count())->toBe(0)
        ->and(DnsEntry::count())->toBe(1)
        ->and($entry->syncStates()->count())->toBe(0);
});

test('drift check on a disabled provider is rejected with feedback', function () {
    $provider = Provider::factory()->cloudflare()->disabled()->create();

    $this->post("/providers/{$provider->id}/check")->assertRedirect();

    Queue::assertNotPushed(CheckProviderDrift::class);
    expect(session('error'))->toContain('disabled');
});

test('underscore-prefixed labels are valid entry names', function () {
    foreach (['_dmarc.example.com', '_sip._tcp.example.com'] as $name) {
        $this->post('/entries', [
            'name' => $name,
            'type' => 'TXT',
            'content' => 'v=1',
        ])->assertSessionHasNoErrors();
    }

    $this->post('/entries', [
        'name' => 'bad_label.example.com',
        'type' => 'TXT',
        'content' => 'v=1',
    ])->assertSessionHasErrors('name');
});

test('dns entry validation rejects bad payloads', function () {
    $this->post('/entries', [
        'name' => 'not a domain!!',
        'type' => 'A',
        'content' => '10.0.0.1',
    ])->assertSessionHasErrors('name');

    $this->post('/entries', [
        'name' => 'host.example.com',
        'type' => 'A',
        'content' => 'not-an-ip',
    ])->assertSessionHasErrors('content');

    $this->post('/entries', [
        'name' => 'example.com',
        'type' => 'MX',
        'content' => 'mail.example.com',
    ])->assertSessionHasErrors('priority');

    $this->post('/entries', [
        'name' => 'host.example.com',
        'type' => 'A',
        'content' => '10.0.0.1',
        'ttl' => 5,
    ])->assertSessionHasErrors('ttl');
});

test('entries index filters by search type provider and status', function () {
    $provider = Provider::factory()->cloudflare()->create();

    $a = DnsEntry::factory()->create(['name' => 'alpha.example.com', 'type' => 'A']);
    $cname = DnsEntry::factory()->cname()->create(['name' => 'beta.example.com']);
    $a->syncStates()->create(['provider_id' => $provider->id, 'sync_status' => 'drifted', 'external_id' => 'cf-1']);

    $this->get('/entries?search=alpha')->assertInertia(fn ($p) => $p->has('entries.data', 1));
    $this->get('/entries?type=CNAME')->assertInertia(fn ($p) => $p->has('entries.data', 1)->where('entries.data.0.name', 'beta.example.com'));
    $this->get("/entries?provider={$provider->id}")->assertInertia(fn ($p) => $p->has('entries.data', 1));
    $this->get('/entries?status=drifted')->assertInertia(fn ($p) => $p->has('entries.data', 1)->where('entries.data.0.name', 'alpha.example.com'));
});
