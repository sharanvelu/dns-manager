<?php

declare(strict_types = 1);

use App\Models\User;
use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\ZoneProvider;
use App\Jobs\CheckProviderDrift;
use App\Jobs\CheckProviderHealth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();
});

function attachedEntry(ZoneProvider $attachment, array $attributes = [], string $status = 'synced'): DnsEntry
{
    $entry = DnsEntry::factory()->create($attributes + ['dns_zone_id' => $attachment->dns_zone_id]);

    $entry->syncStates()->create([
        'zone_provider_id' => $attachment->id,
        'sync_status' => $status,
        'external_id' => "ext-{$entry->id}",
    ]);

    return $entry;
}

test('providers index renders with connector descriptors and zone summaries', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $provider = Provider::factory()->cloudflare()->create();
    $attachment = ZoneProvider::factory()->create([
        'dns_zone_id' => $zone->id,
        'provider_id' => $provider->id,
        'config' => ['zone_id' => 'cf-zone-1'],
    ]);

    attachedEntry($attachment);
    attachedEntry($attachment, ['content' => '10.0.0.2'], 'drifted');

    $this->get('/providers')->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('providers/index')
            ->has('providers', 1)
            ->where('providers.0.recordsCount', 2)
            ->where('providers.0.syncedCount', 1)
            ->has('providers.0.zones', 1)
            ->where('providers.0.zones.0.zoneProviderId', $attachment->id)
            ->where('providers.0.zones.0.zoneId', $zone->id)
            ->where('providers.0.zones.0.zoneName', 'example.com')
            ->where('providers.0.zones.0.enabled', true)
            ->has('connectors', 3)
            ->where('connectors.0.type', 'cloudflare')
            ->has('connectors.0.configSchema')
            ->has('connectors.0.zoneConfigSchema')
            ->has('allZones', 1)
            ->where('allZones.0.id', $zone->id)
            ->where('allZones.0.name', 'example.com')
    );
});

test('providers index exposes every zone so the UI can compute unattached zones', function () {
    DnsZone::factory()->create(['name' => 'beta.test']);
    DnsZone::factory()->create(['name' => 'alpha.test']);

    $provider = Provider::factory()->cloudflare()->create();

    $this->get('/providers')->assertOk()->assertInertia(
        fn ($page) => $page
            ->has('allZones', 2)
            ->where('allZones.0.name', 'alpha.test')
            ->where('allZones.1.name', 'beta.test')
            ->where('providers.0.id', $provider->id)
            ->where('providers.0.zones', [])
    );
});

test('the cloudflare config schema no longer asks for a zone id', function () {
    $this->get('/providers')->assertOk()->assertInertia(
        fn ($page) => $page->where(
            'connectors.0.configSchema',
            fn ($schema) => ! collect($schema)->contains(fn ($field) => $field['key'] === 'zone_id'),
        ),
    );
});

test('a cloudflare provider can be created and gets a health check, not a drift check', function () {
    $this->post('/providers', [
        'name' => 'My Zone',
        'type' => 'cloudflare',
        'enabled' => true,
        'managed_record_types' => ['A', 'CNAME'],
        'config' => ['api_token' => 'tok-123'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $provider = Provider::sole();
    expect($provider->config['api_token'])->toBe('tok-123');
    Queue::assertPushed(CheckProviderHealth::class);
    Queue::assertNotPushed(CheckProviderDrift::class);
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
        'config' => ['adopt_existing' => true],
    ])->assertSessionHasErrors('config.api_token');
});

test('blank secrets on update keep the stored value and queue a drift check', function () {
    $provider = Provider::factory()->cloudflare()->create([
        'config' => ['api_token' => 'original-secret'],
    ]);

    $this->put("/providers/{$provider->id}", [
        'name' => 'Renamed',
        'type' => 'cloudflare',
        'enabled' => true,
        'managed_record_types' => ['A'],
        'config' => ['api_token' => ''],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $provider->refresh();
    expect($provider->name)->toBe('Renamed')
        ->and($provider->config['api_token'])->toBe('original-secret');

    Queue::assertPushed(CheckProviderDrift::class);
});

test('secrets are never exposed on the providers page', function () {
    Provider::factory()->cloudflare()->create([
        'config' => ['api_token' => 'super-secret', 'adopt_existing' => true],
    ]);

    $this->get('/providers')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('providers.0.config.api_token', '')
            ->where('providers.0.config.adopt_existing', true))
        ->assertDontSee('super-secret');
});

test('test connection endpoint proxies the connector result', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones?*' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [],
            'result' => [], 'result_info' => ['total_count' => 3],
        ]),
    ]);

    $this->postJson('/providers/test', [
        'type' => 'cloudflare',
        'config' => ['api_token' => 'tok'],
    ])->assertOk()->assertJson(['ok' => true])->assertJsonPath('details.zones', 3);
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

test('deleting a provider keeps dns entries but drops its attachments and states', function () {
    $attachment = ZoneProvider::factory()->cloudflare()->create();
    $entry = attachedEntry($attachment);

    $this->delete("/providers/{$attachment->provider_id}")->assertRedirect();

    expect(Provider::count())->toBe(0)
        ->and(ZoneProvider::count())->toBe(0)
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
    $zone = DnsZone::factory()->create(['name' => 'example.com']);

    foreach (['_dmarc', '_sip._tcp'] as $name) {
        $this->post('/entries', [
            'dns_zone_id' => $zone->id,
            'name' => $name,
            'type' => 'TXT',
            'content' => 'v=1',
        ])->assertSessionHasNoErrors();
    }

    $this->post('/entries', [
        'dns_zone_id' => $zone->id,
        'name' => 'bad_label',
        'type' => 'TXT',
        'content' => 'v=1',
    ])->assertSessionHasErrors('name');
});

test('dns entry validation rejects bad payloads', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->post('/entries', [
        'dns_zone_id' => $zone->id,
        'name' => 'not a name!!',
        'type' => 'A',
        'content' => '10.0.0.1',
    ])->assertSessionHasErrors('name');

    $this->post('/entries', [
        'dns_zone_id' => $zone->id,
        'name' => 'host',
        'type' => 'A',
        'content' => 'not-an-ip',
    ])->assertSessionHasErrors('content');

    $this->post('/entries', [
        'dns_zone_id' => $zone->id,
        'name' => '@',
        'type' => 'MX',
        'content' => 'mail.example.com',
    ])->assertSessionHasErrors('priority');

    $this->post('/entries', [
        'dns_zone_id' => $zone->id,
        'name' => 'host',
        'type' => 'A',
        'content' => '10.0.0.1',
        'ttl' => 5,
    ])->assertSessionHasErrors('ttl');

    $this->post('/entries', [
        'name' => 'host',
        'type' => 'A',
        'content' => '10.0.0.1',
    ])->assertSessionHasErrors('dns_zone_id');
});

test('entries index filters by search type provider and status', function () {
    $attachment = ZoneProvider::factory()->cloudflare()->create();

    attachedEntry($attachment, ['name' => 'alpha', 'type' => 'A'], 'drifted');
    DnsEntry::factory()->cname()->create(['dns_zone_id' => $attachment->dns_zone_id, 'name' => 'beta']);

    $this->get('/entries?search=alpha')->assertInertia(fn ($p) => $p->has('entries.data', 1));
    $this->get('/entries?type=CNAME')->assertInertia(fn ($p) => $p->has('entries.data', 1)->where('entries.data.0.name', 'beta'));
    $this->get("/entries?provider={$attachment->provider_id}")->assertInertia(fn ($p) => $p->has('entries.data', 1));
    $this->get('/entries?status=drifted')->assertInertia(fn ($p) => $p->has('entries.data', 1)->where('entries.data.0.name', 'alpha'));
});
