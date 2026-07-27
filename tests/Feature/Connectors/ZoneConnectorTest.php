<?php

declare(strict_types = 1);

use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\ZoneProvider;
use Illuminate\Support\Sleep;
use App\Connectors\PiholeConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use App\Connectors\ConnectorRegistry;
use Illuminate\Support\Facades\Cache;
use App\Connectors\CloudflareConnector;
use App\Connectors\Exceptions\ConnectorException;

const CF_ZONES_BASE = 'https://api.cloudflare.com/client/v4';

function cfZoneEnvelope(mixed $result, array $overrides = []): array
{
    return array_merge([
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => $result,
    ], $overrides);
}

/**
 * A Cloudflare provider attached to a zone, with distinct provider-level
 * and zone-level zone_ids so precedence is observable.
 */
function cloudflareAttachment(
    string $zoneName = 'example.com',
    ?array $zoneConfig = ['zone_id' => 'zone-abc-123'],
    array $providerConfig = [],
): ZoneProvider {
    $provider = Provider::factory()->cloudflare()->create([
        'config' => array_merge([
            'api_token' => 'test-token',
            'zone_id' => 'provider-level-zone',
        ], $providerConfig),
    ]);

    $zone = DnsZone::factory()->create(['name' => $zoneName]);

    return ZoneProvider::factory()->create([
        'dns_zone_id' => $zone->id,
        'provider_id' => $provider->id,
        'config' => $zoneConfig,
    ]);
}

describe('Cloudflare testZone', function () {
    it('succeeds when the remote zone name matches the local zone', function () {
        Http::fake([
            CF_ZONES_BASE . '/zones/zone-abc-123' => Http::response(
                cfZoneEnvelope(['id' => 'zone-abc-123', 'name' => 'Example.COM', 'status' => 'active']),
            ),
        ]);

        $zoneProvider = cloudflareAttachment('example.com');
        $result = (new CloudflareConnector($zoneProvider->provider, $zoneProvider))->testZone();

        expect($result->ok)->toBeTrue()
            ->and($result->message)->toBe('Connected to zone Example.COM')
            ->and($result->details)->toBe(['zone' => 'Example.COM', 'status' => 'active']);
    });

    it('fails when the zone ID belongs to a different zone', function () {
        Http::fake([
            CF_ZONES_BASE . '/zones/zone-abc-123' => Http::response(
                cfZoneEnvelope(['id' => 'zone-abc-123', 'name' => 'other.net', 'status' => 'active']),
            ),
        ]);

        $zoneProvider = cloudflareAttachment('example.com');
        $result = (new CloudflareConnector($zoneProvider->provider, $zoneProvider))->testZone();

        expect($result->ok)->toBeFalse()
            ->and($result->message)->toBe('Zone ID belongs to other.net — expected example.com');
    });

    it('fails with the Cloudflare error message when the zone lookup errors', function () {
        Http::fake([
            CF_ZONES_BASE . '/zones/zone-abc-123' => Http::response(cfZoneEnvelope(null, [
                'success' => false,
                'errors' => [['code' => 7003, 'message' => 'Could not route to /zones/zone-abc-123']],
            ]), 404),
        ]);

        $zoneProvider = cloudflareAttachment('example.com');
        $result = (new CloudflareConnector($zoneProvider->provider, $zoneProvider))->testZone();

        expect($result->ok)->toBeFalse()
            ->and($result->message)->toContain('Zone lookup failed')
            ->and($result->message)->toContain('[7003]');
    });

    it('throws a ConnectorException without a zone attachment', function () {
        Http::fake();

        $provider = Provider::factory()->cloudflare()->create([
            'config' => ['api_token' => 'test-token', 'zone_id' => 'zone-abc-123'],
        ]);

        expect(fn () => (new CloudflareConnector($provider))->testZone())
            ->toThrow(ConnectorException::class, 'Cloudflare operation requires a zone attachment');

        Http::assertNothingSent();
    });
});

describe('Cloudflare discoverZoneConfig', function () {
    it('returns the zone_id when the zone is found by name', function () {
        Http::fake([
            CF_ZONES_BASE . '/zones?*' => Http::response(cfZoneEnvelope([
                ['id' => 'discovered-zone-id', 'name' => 'example.com', 'status' => 'active'],
            ])),
        ]);

        $provider = Provider::factory()->cloudflare()->create([
            'config' => ['api_token' => 'test-token', 'zone_id' => 'zone-abc-123'],
        ]);

        $config = (new CloudflareConnector($provider))->discoverZoneConfig('example.com');

        expect($config)->toBe(['zone_id' => 'discovered-zone-id']);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), CF_ZONES_BASE . '/zones?')
            && $request->data()['name'] === 'example.com'
            && $request->data()['per_page'] == 1);
    });

    it('returns null when no zone matches the name', function () {
        Http::fake([
            CF_ZONES_BASE . '/zones?*' => Http::response(cfZoneEnvelope([])),
        ]);

        $provider = Provider::factory()->cloudflare()->create([
            'config' => ['api_token' => 'test-token', 'zone_id' => 'zone-abc-123'],
        ]);

        expect((new CloudflareConnector($provider))->discoverZoneConfig('missing.example'))->toBeNull();
    });

    it('returns null when the API call fails', function () {
        Http::fake([
            CF_ZONES_BASE . '/zones?*' => Http::response(cfZoneEnvelope(null, [
                'success' => false,
                'errors' => [['code' => 10000, 'message' => 'Authentication error']],
            ]), 403),
        ]);

        $provider = Provider::factory()->cloudflare()->create([
            'config' => ['api_token' => 'test-token', 'zone_id' => 'zone-abc-123'],
        ]);

        expect((new CloudflareConnector($provider))->discoverZoneConfig('example.com'))->toBeNull();
    });
});

describe('merged config precedence', function () {
    it('prefers the ZoneProvider zone_id over the provider-level one', function () {
        Http::fake([
            CF_ZONES_BASE . '/zones/zone-level-wins' => Http::response(
                cfZoneEnvelope(['id' => 'zone-level-wins', 'name' => 'example.com', 'status' => 'active']),
            ),
        ]);

        $zoneProvider = cloudflareAttachment('example.com', ['zone_id' => 'zone-level-wins']);
        $result = (new CloudflareConnector($zoneProvider->provider, $zoneProvider))->testZone();

        expect($result->ok)->toBeTrue();

        Http::assertSent(fn (Request $request) => $request->url() === CF_ZONES_BASE . '/zones/zone-level-wins');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'provider-level-zone'));
    });

    it('falls back to the provider-level zone_id when the attachment has no config', function () {
        Http::fake([
            CF_ZONES_BASE . '/zones/provider-level-zone' => Http::response(
                cfZoneEnvelope(['id' => 'provider-level-zone', 'name' => 'example.com', 'status' => 'active']),
            ),
        ]);

        $zoneProvider = cloudflareAttachment('example.com', zoneConfig: null);
        $result = (new CloudflareConnector($zoneProvider->provider, $zoneProvider))->testZone();

        expect($result->ok)->toBeTrue();

        Http::assertSent(fn (Request $request) => $request->url() === CF_ZONES_BASE . '/zones/provider-level-zone');
    });
});

describe('ConnectorRegistry zone support', function () {
    it('builds a connector with zone context from a ZoneProvider', function () {
        Http::fake([
            CF_ZONES_BASE . '/zones/zone-abc-123' => Http::response(
                cfZoneEnvelope(['id' => 'zone-abc-123', 'name' => 'example.com', 'status' => 'active']),
            ),
        ]);

        $zoneProvider = cloudflareAttachment('example.com');

        $connector = app(ConnectorRegistry::class)->for($zoneProvider);

        expect($connector)->toBeInstanceOf(CloudflareConnector::class)
            ->and($connector->testZone()->ok)->toBeTrue();
    });

    it('still builds a zoneless connector from a Provider', function () {
        $provider = Provider::factory()->cloudflare()->create([
            'config' => ['api_token' => 'test-token', 'zone_id' => 'zone-abc-123'],
        ]);

        $connector = app(ConnectorRegistry::class)->for($provider);

        expect($connector)->toBeInstanceOf(CloudflareConnector::class)
            ->and(fn () => $connector->testZone())->toThrow(ConnectorException::class);
    });

    it('exposes zoneConfigSchema and supportsZones in descriptors', function () {
        $descriptors = collect(app(ConnectorRegistry::class)->descriptors())->keyBy('type');

        $cloudflare = $descriptors->get('cloudflare');
        expect($cloudflare['capabilities']['supportsZones'])->toBeTrue()
            ->and(array_column($cloudflare['zoneConfigSchema'], 'key'))->toBe(['zone_id']);

        $pihole = $descriptors->get('pihole');
        expect($pihole['capabilities']['supportsZones'])->toBeFalse()
            ->and($pihole['zoneConfigSchema'])->toBe([]);
    });
});

describe('Pi-hole zone attachments', function () {
    function fakeZonePihole(): void
    {
        Http::fake([
            'https://pihole.zone-test/api/auth' => Http::response([
                'session' => ['valid' => true, 'sid' => 'test-sid', 'csrf' => 'test-csrf', 'validity' => 300],
                'took' => 0.1,
            ]),
            'https://pihole.zone-test/api/config/dns/hosts' => Http::response([
                'config' => ['dns' => ['hosts' => []]],
                'took' => 0.1,
            ]),
            'https://pihole.zone-test/api/config/dns/cnameRecords' => Http::response([
                'config' => ['dns' => ['cnameRecords' => []]],
                'took' => 0.1,
            ]),
            'https://pihole.zone-test/api/config/dns/*' => Http::response(['took' => 0.1], 201),
        ]);
    }

    function piholeZoneAttachment(Provider $provider): ZoneProvider
    {
        return ZoneProvider::factory()->create([
            'dns_zone_id' => DnsZone::factory()->create()->id,
            'provider_id' => $provider->id,
            'config' => null,
        ]);
    }

    it('does not support zones', function () {
        expect(PiholeConnector::capabilities()->supportsZones)->toBeFalse();
    });

    it('validates a zone attachment via the plain connection test', function () {
        Http::fake([
            'https://pihole.zone-test/api/auth' => Http::response([
                'session' => ['valid' => true, 'sid' => 'test-sid', 'csrf' => 'test-csrf', 'validity' => 300],
                'took' => 0.1,
            ]),
            'https://pihole.zone-test/api/info/version' => Http::response([
                'version' => ['core' => ['local' => ['version' => 'v6.1']]],
                'took' => 0.1,
            ]),
        ]);

        $provider = Provider::factory()->pihole()->create([
            'config' => ['base_url' => 'https://pihole.zone-test', 'app_password' => 'super-secret', 'verify_tls' => true],
        ]);
        $zoneProvider = piholeZoneAttachment($provider);

        $result = app(ConnectorRegistry::class)->for($zoneProvider)->testZone();

        expect($result->ok)->toBeTrue()
            ->and($result->message)->toContain('v6.1');
    });

    it('keys session state per provider, not per zone attachment', function () {
        fakeZonePihole();

        $provider = Provider::factory()->pihole()->create([
            'config' => ['base_url' => 'https://pihole.zone-test', 'app_password' => 'super-secret', 'verify_tls' => true],
        ]);

        $first = piholeZoneAttachment($provider);
        $second = piholeZoneAttachment($provider);

        expect($first->id)->not->toBe($second->id);

        $entry = DnsEntry::factory()->cname('target.example.com')->create([
            'name' => 'alias',
            'ttl' => null,
        ]);

        // A CNAME write through the first attachment stamps the restart
        // cooldown; a session through the SECOND attachment of the same
        // provider must observe it — shared state is keyed by provider id.
        $first->connector()->createRecord($entry);
        Sleep::assertNeverSlept();

        $second->connector()->listRecords();
        Sleep::assertSleptTimes(1);

        // And the session lock lives under the provider id, released after use.
        expect(Cache::lock("pihole-session:{$provider->id}", 5)->get())->toBeTrue();
    });
});
