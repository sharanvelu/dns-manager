<?php

use App\Models\DnsZone;
use App\Models\Provider;
use App\Models\User;
use App\Models\ZoneProvider;
use App\Models\ZoneUser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();
});

function cloudflareZoneList(array $zones): array
{
    return ['success' => true, 'errors' => [], 'result' => $zones];
}

test('a cloudflare provider can be attached with an explicit zone id', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $provider = Provider::factory()->cloudflare()->create();

    $this->post("/zones/{$zone->id}/providers", [
        'provider_id' => $provider->id,
        'config' => ['zone_id' => 'cf-zone-1'],
        'enabled' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $attachment = ZoneProvider::sole();
    expect($attachment->dns_zone_id)->toBe($zone->id)
        ->and($attachment->provider_id)->toBe($provider->id)
        ->and($attachment->config['zone_id'])->toBe('cf-zone-1')
        ->and($attachment->enabled)->toBeTrue();

    $activity = Activity::query()->where('log_name', 'zones')->where('event', 'provider-attached')->sole();
    expect($activity->properties['provider'])->toBe($provider->name)
        ->and($activity->properties->toArray())->not->toHaveKey('config');
});

test('attaching with a blank config discovers the zone id from cloudflare', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $provider = Provider::factory()->cloudflare()->create();

    Http::fake([
        'api.cloudflare.com/*' => Http::response(cloudflareZoneList([
            ['id' => 'cf-discovered-1', 'name' => 'example.com', 'status' => 'active'],
        ])),
    ]);

    $this->post("/zones/{$zone->id}/providers", [
        'provider_id' => $provider->id,
        'config' => ['zone_id' => ''],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(ZoneProvider::sole()->config['zone_id'])->toBe('cf-discovered-1');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/zones?')
        && $request['name'] === 'example.com');
});

test('attaching fails with feedback when discovery finds nothing', function () {
    $zone = DnsZone::factory()->create(['name' => 'unknown.dev']);
    $provider = Provider::factory()->cloudflare()->create();

    Http::fake(['api.cloudflare.com/*' => Http::response(cloudflareZoneList([]))]);

    $this->post("/zones/{$zone->id}/providers", [
        'provider_id' => $provider->id,
    ])->assertRedirect();

    expect(ZoneProvider::count())->toBe(0)
        ->and(session('error'))->toContain('Zone ID');
});

test('attaching the same provider twice is rejected', function () {
    $zone = DnsZone::factory()->create();
    $provider = Provider::factory()->cloudflare()->create();
    ZoneProvider::factory()->create(['dns_zone_id' => $zone->id, 'provider_id' => $provider->id]);

    $this->post("/zones/{$zone->id}/providers", [
        'provider_id' => $provider->id,
        'config' => ['zone_id' => 'cf-zone-1'],
    ])->assertSessionHasErrors('provider_id');

    expect(ZoneProvider::count())->toBe(1);
});

test('an attachment update can toggle enabled while keeping the stored config', function () {
    $attachment = ZoneProvider::factory()->cloudflare('cf-keep-1')->create();

    $this->put("/zones/{$attachment->dns_zone_id}/providers/{$attachment->id}", [
        'enabled' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $attachment->refresh();
    expect($attachment->enabled)->toBeFalse()
        ->and($attachment->config['zone_id'])->toBe('cf-keep-1');
});

test('an attachment update can change the zone config', function () {
    $attachment = ZoneProvider::factory()->cloudflare('cf-old-1')->create();

    $this->put("/zones/{$attachment->dns_zone_id}/providers/{$attachment->id}", [
        'enabled' => true,
        'config' => ['zone_id' => 'cf-new-1'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($attachment->refresh()->config['zone_id'])->toBe('cf-new-1');

    $activity = Activity::query()->where('log_name', 'zones')->where('event', 'attachment-updated')->sole();
    expect($activity->properties['config_changed'])->toBeTrue()
        ->and($activity->properties->toArray())->not->toHaveKey('zone_id');
});

test('a blank required zone field is rejected on update', function () {
    $attachment = ZoneProvider::factory()->cloudflare('cf-old-1')->create();

    $this->put("/zones/{$attachment->dns_zone_id}/providers/{$attachment->id}", [
        'config' => ['zone_id' => ''],
    ])->assertSessionHasErrors('config.zone_id');
});

test('detaching a provider removes the attachment but keeps remote records', function () {
    $attachment = ZoneProvider::factory()->cloudflare()->create();

    $this->delete("/zones/{$attachment->dns_zone_id}/providers/{$attachment->id}")->assertRedirect();

    expect(ZoneProvider::count())->toBe(0)
        ->and(session('success'))->toContain('NOT deleted');

    Activity::query()->where('log_name', 'zones')->where('event', 'provider-detached')->sole();
});

test('attachments are scoped to their zone', function () {
    $attachment = ZoneProvider::factory()->cloudflare()->create();
    $otherZone = DnsZone::factory()->create();

    $this->put("/zones/{$otherZone->id}/providers/{$attachment->id}", ['enabled' => false])->assertNotFound();
    $this->delete("/zones/{$otherZone->id}/providers/{$attachment->id}")->assertNotFound();
});

test('the zone test endpoint proxies the connector result', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $attachment = ZoneProvider::factory()->cloudflare('cf-zone-1')->create(['dns_zone_id' => $zone->id]);

    Http::fake([
        'api.cloudflare.com/client/v4/zones/cf-zone-1' => Http::response([
            'success' => true, 'errors' => [], 'result' => ['id' => 'cf-zone-1', 'name' => 'example.com', 'status' => 'active'],
        ]),
    ]);

    $this->postJson("/zones/{$zone->id}/providers/{$attachment->id}/test")
        ->assertOk()
        ->assertJson(['ok' => true])
        ->assertJsonPath('details.zone', 'example.com');
});

test('the discover endpoint reports found and not-found zones', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $provider = Provider::factory()->cloudflare()->create();

    Http::fake([
        'api.cloudflare.com/*' => Http::sequence()
            ->push(cloudflareZoneList([['id' => 'cf-found-1', 'name' => 'example.com', 'status' => 'active']]))
            ->push(cloudflareZoneList([])),
    ]);

    $this->postJson("/zones/{$zone->id}/providers/discover", ['provider_id' => $provider->id])
        ->assertOk()
        ->assertJson(['found' => true, 'config' => ['zone_id' => 'cf-found-1']]);

    $this->postJson("/zones/{$zone->id}/providers/discover", ['provider_id' => $provider->id])
        ->assertOk()
        ->assertJson(['found' => false]);
});

test('creating a zone auto-attaches enabled zoneless providers only', function () {
    $pihole = Provider::factory()->pihole()->create();
    $disabledPihole = Provider::factory()->pihole()->disabled()->create();
    $cloudflare = Provider::factory()->cloudflare()->create();

    $this->post('/zones', ['name' => 'example.com'])->assertSessionHasNoErrors();

    $zone = DnsZone::sole();
    expect(ZoneProvider::where('provider_id', $pihole->id)->where('dns_zone_id', $zone->id)->exists())->toBeTrue()
        ->and(ZoneProvider::where('provider_id', $disabledPihole->id)->exists())->toBeFalse()
        ->and(ZoneProvider::where('provider_id', $cloudflare->id)->exists())->toBeFalse();
});

test('creating a zoneless provider auto-attaches it to every existing zone', function () {
    $zones = DnsZone::factory()->count(2)->create();

    $this->post('/providers', [
        'name' => 'Pi-hole',
        'type' => 'pihole',
        'enabled' => true,
        'managed_record_types' => ['A', 'CNAME'],
        'config' => ['base_url' => 'https://pi.hole', 'app_password' => 'pw'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $provider = Provider::sole();
    expect(ZoneProvider::where('provider_id', $provider->id)->count())->toBe(2)
        ->and($zones->every(fn ($zone) => ZoneProvider::where('dns_zone_id', $zone->id)->where('provider_id', $provider->id)->exists()))->toBeTrue();
});

test('creating a zone-scoped provider does not auto-attach it', function () {
    DnsZone::factory()->create();

    $this->post('/providers', [
        'name' => 'CF',
        'type' => 'cloudflare',
        'enabled' => true,
        'managed_record_types' => ['A'],
        'config' => ['api_token' => 'tok'],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(ZoneProvider::count())->toBe(0);
});

test('a detached zoneless provider is not resurrected by unrelated operations', function () {
    $pihole = Provider::factory()->pihole()->create();

    $this->post('/zones', ['name' => 'example.com']);
    $zone = DnsZone::sole();
    $attachment = ZoneProvider::sole();

    $this->delete("/zones/{$zone->id}/providers/{$attachment->id}")->assertRedirect();

    // Creating another zone attaches only to the new zone; updating the
    // provider re-attaches nothing.
    $this->post('/zones', ['name' => 'example.org']);
    $this->put("/providers/{$pihole->id}", [
        'name' => 'Pi-hole renamed',
        'type' => 'pihole',
        'enabled' => true,
        'managed_record_types' => ['A'],
        'config' => ['base_url' => 'https://pi.hole', 'app_password' => ''],
    ])->assertSessionHasNoErrors();

    expect(ZoneProvider::where('dns_zone_id', $zone->id)->exists())->toBeFalse()
        ->and(ZoneProvider::where('provider_id', $pihole->id)->count())->toBe(1);
});

test('attachments require attachment management on the zone', function () {
    $zone = DnsZone::factory()->create();
    $provider = Provider::factory()->cloudflare()->create();
    $attachment = ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $zone->id]);

    // A record-managing grant on the same zone is not enough.
    $dnsManager = User::factory()->noRoles()->create();
    ZoneUser::factory()->dnsManager()->create(['user_id' => $dnsManager->id, 'dns_zone_id' => $zone->id]);

    // A provider-managing grant works — but only on the granted zone.
    $providerManager = User::factory()->noRoles()->create();
    ZoneUser::factory()->providerManager()->create(['user_id' => $providerManager->id, 'dns_zone_id' => $zone->id]);
    $otherZone = DnsZone::factory()->create();

    $this->actingAs($dnsManager);

    $payload = ['provider_id' => $provider->id, 'config' => ['zone_id' => 'z1']];
    $this->post("/zones/{$zone->id}/providers", $payload)->assertForbidden();
    $this->put("/zones/{$zone->id}/providers/{$attachment->id}", ['enabled' => false])->assertForbidden();
    $this->delete("/zones/{$zone->id}/providers/{$attachment->id}")->assertForbidden();
    $this->postJson("/zones/{$zone->id}/providers/{$attachment->id}/test")->assertForbidden();
    $this->postJson("/zones/{$zone->id}/providers/discover", ['provider_id' => $provider->id])->assertForbidden();

    $this->actingAs($providerManager);

    $this->post("/zones/{$otherZone->id}/providers", $payload)->assertForbidden();
    $this->post("/zones/{$zone->id}/providers", $payload)->assertRedirect()->assertSessionHasNoErrors();
});
