<?php

use App\Connectors\CloudflareConnector;
use App\Connectors\DTOs\RemoteRecord;
use App\Connectors\Exceptions\ConnectorException;
use App\Enums\RecordType;
use App\Models\DnsEntry;
use App\Models\Provider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function cloudflareEnvelope(mixed $result, array $overrides = []): array
{
    return array_merge([
        'success' => true,
        'errors' => [],
        'messages' => [],
        'result' => $result,
    ], $overrides);
}

function cloudflareApiRecord(array $overrides = []): array
{
    return array_merge([
        'id' => 'rec-'.fake()->uuid(),
        'type' => 'A',
        'name' => 'www.example.com',
        'content' => '192.0.2.1',
        'ttl' => 1,
        'proxied' => false,
        'comment' => null,
    ], $overrides);
}

beforeEach(function () {
    $this->provider = Provider::factory()->cloudflare()->create([
        'config' => [
            'api_token' => 'test-token',
            'zone_id' => 'zone-abc-123',
        ],
    ]);

    $this->connector = new CloudflareConnector($this->provider);
    $this->recordsUrl = 'https://api.cloudflare.com/client/v4/zones/zone-abc-123/dns_records';
});

describe('createRecord', function () {
    it('posts the correct payload for an A record and returns the id', function () {
        Http::fake([
            $this->recordsUrl => Http::response(cloudflareEnvelope(cloudflareApiRecord(['id' => 'rec-created-1']))),
        ]);

        $entry = DnsEntry::factory()->create([
            'name' => 'www.example.com',
            'type' => RecordType::A,
            'content' => '192.0.2.1',
            'ttl' => null,
            'proxied' => true,
        ]);

        expect($this->connector->createRecord($entry))->toBe('rec-created-1');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === $this->recordsUrl
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['type'] === 'A'
                && $request['name'] === 'www.example.com'
                && $request['content'] === '192.0.2.1'
                && $request['ttl'] === 1
                && $request['proxied'] === true;
        });
    });

    it('sends a top-level priority for MX records, defaulting to 10', function () {
        Http::fake([
            $this->recordsUrl => Http::response(cloudflareEnvelope(cloudflareApiRecord(['id' => 'rec-mx-1', 'type' => 'MX']))),
        ]);

        $entry = DnsEntry::factory()->mx()->create([
            'name' => 'example.com',
            'ttl' => 3600,
            'priority' => null,
        ]);

        $this->connector->createRecord($entry);

        Http::assertSent(function (Request $request) {
            return $request['type'] === 'MX'
                && $request['content'] === 'mail.example.com'
                && $request['priority'] === 10
                && $request['ttl'] === 3600
                && ! array_key_exists('proxied', $request->data());
        });
    });

    it('wraps TXT content in double quotes', function () {
        Http::fake([
            $this->recordsUrl => Http::response(cloudflareEnvelope(cloudflareApiRecord(['id' => 'rec-txt-1', 'type' => 'TXT']))),
        ]);

        $entry = DnsEntry::factory()->create([
            'name' => 'example.com',
            'type' => RecordType::TXT,
            'content' => 'v=spf1 include:_spf.example.com -all',
        ]);

        $this->connector->createRecord($entry);

        Http::assertSent(fn (Request $request) => $request['content'] === '"v=spf1 include:_spf.example.com -all"');
    });

    it('does not double-quote already quoted TXT content', function () {
        Http::fake([
            $this->recordsUrl => Http::response(cloudflareEnvelope(cloudflareApiRecord(['id' => 'rec-txt-2', 'type' => 'TXT']))),
        ]);

        $entry = DnsEntry::factory()->create([
            'name' => 'example.com',
            'type' => RecordType::TXT,
            'content' => '"already-quoted"',
        ]);

        $this->connector->createRecord($entry);

        Http::assertSent(fn (Request $request) => $request['content'] === '"already-quoted"');
    });

    it('builds a data object for SRV records from "weight port target" content', function () {
        Http::fake([
            $this->recordsUrl => Http::response(cloudflareEnvelope(cloudflareApiRecord(['id' => 'rec-srv-1', 'type' => 'SRV']))),
        ]);

        $entry = DnsEntry::factory()->create([
            'name' => '_sip._tcp.example.com',
            'type' => RecordType::SRV,
            'content' => '60 5060 sip.example.com',
            'priority' => 5,
        ]);

        $this->connector->createRecord($entry);

        Http::assertSent(function (Request $request) {
            return $request['type'] === 'SRV'
                && $request['data'] === [
                    'priority' => 5,
                    'weight' => 60,
                    'port' => 5060,
                    'target' => 'sip.example.com',
                ]
                && ! array_key_exists('content', $request->data());
        });
    });

    it('throws a ConnectorException for malformed SRV content', function () {
        Http::fake();

        $entry = DnsEntry::factory()->create([
            'name' => '_sip._tcp.example.com',
            'type' => RecordType::SRV,
            'content' => 'not-a-valid-srv-content',
        ]);

        expect(fn () => $this->connector->createRecord($entry))
            ->toThrow(ConnectorException::class, 'weight port target');

        Http::assertNothingSent();
    });

    it('builds a data object for CAA records', function () {
        Http::fake([
            $this->recordsUrl => Http::response(cloudflareEnvelope(cloudflareApiRecord(['id' => 'rec-caa-1', 'type' => 'CAA']))),
        ]);

        $entry = DnsEntry::factory()->create([
            'name' => 'example.com',
            'type' => RecordType::CAA,
            'content' => '0 issue "letsencrypt.org"',
        ]);

        $this->connector->createRecord($entry);

        Http::assertSent(function (Request $request) {
            return $request['type'] === 'CAA'
                && $request['data'] === [
                    'flags' => 0,
                    'tag' => 'issue',
                    'value' => 'letsencrypt.org',
                ];
        });
    });

    it('throws a ConnectorException with the Cloudflare error message on a 400 response', function () {
        Http::fake([
            $this->recordsUrl => Http::response(cloudflareEnvelope(null, [
                'success' => false,
                'errors' => [['code' => 81057, 'message' => 'Record already exists.']],
            ]), 400),
        ]);

        $entry = DnsEntry::factory()->create();

        expect(fn () => $this->connector->createRecord($entry))
            ->toThrow(ConnectorException::class, '[81057] Record already exists.');
    });
});

describe('updateRecord', function () {
    it('PUTs the full payload to the record id and returns the id', function () {
        Http::fake([
            $this->recordsUrl.'/rec-42' => Http::response(cloudflareEnvelope(cloudflareApiRecord(['id' => 'rec-42']))),
        ]);

        $entry = DnsEntry::factory()->create([
            'name' => 'app.example.com',
            'type' => RecordType::A,
            'content' => '192.0.2.50',
            'ttl' => 300,
            'proxied' => false,
        ]);

        expect($this->connector->updateRecord($entry, 'rec-42'))->toBe('rec-42');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'PUT'
                && $request->url() === $this->recordsUrl.'/rec-42'
                && $request['name'] === 'app.example.com'
                && $request['content'] === '192.0.2.50'
                && $request['ttl'] === 300
                && $request['proxied'] === false;
        });
    });
});

describe('deleteRecord', function () {
    it('sends a DELETE for the record id', function () {
        Http::fake([
            $this->recordsUrl.'/rec-7' => Http::response(cloudflareEnvelope(['id' => 'rec-7'])),
        ]);

        $this->connector->deleteRecord('rec-7');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === $this->recordsUrl.'/rec-7');
    });

    it('treats a 404 as success', function () {
        Http::fake([
            $this->recordsUrl.'/rec-gone' => Http::response(cloudflareEnvelope(null, [
                'success' => false,
                'errors' => [['code' => 81044, 'message' => 'Record does not exist.']],
            ]), 404),
        ]);

        $this->connector->deleteRecord('rec-gone');

        Http::assertSentCount(1);
    });

    it('throws on other failures', function () {
        Http::fake([
            $this->recordsUrl.'/rec-8' => Http::response(cloudflareEnvelope(null, [
                'success' => false,
                'errors' => [['code' => 10000, 'message' => 'Authentication error']],
            ]), 403),
        ]);

        expect(fn () => $this->connector->deleteRecord('rec-8'))
            ->toThrow(ConnectorException::class, 'Authentication error');
    });
});

describe('listRecords', function () {
    it('maps the API response to RemoteRecords and filters unsupported types', function () {
        Http::fake([
            $this->recordsUrl.'*' => Http::response(cloudflareEnvelope([
                cloudflareApiRecord([
                    'id' => 'rec-a',
                    'type' => 'A',
                    'name' => 'www.example.com',
                    'content' => '192.0.2.1',
                    'ttl' => 1,
                    'proxied' => true,
                ]),
                cloudflareApiRecord([
                    'id' => 'rec-mx',
                    'type' => 'MX',
                    'name' => 'example.com',
                    'content' => 'mail.example.com',
                    'ttl' => 3600,
                    'priority' => 10,
                    'proxied' => false,
                ]),
                cloudflareApiRecord([
                    'id' => 'rec-txt',
                    'type' => 'TXT',
                    'name' => 'example.com',
                    'content' => '"v=spf1 -all"',
                    'ttl' => 300,
                ]),
                cloudflareApiRecord([
                    'id' => 'rec-srv',
                    'type' => 'SRV',
                    'name' => '_sip._tcp.example.com',
                    'content' => '60 5060 sip.example.com',
                    'ttl' => 300,
                    'priority' => 5,
                    'data' => ['priority' => 5, 'weight' => 60, 'port' => 5060, 'target' => 'sip.example.com'],
                ]),
                cloudflareApiRecord([
                    'id' => 'rec-caa',
                    'type' => 'CAA',
                    'name' => 'example.com',
                    'content' => '0 issue "letsencrypt.org"',
                    'ttl' => 300,
                    'data' => ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'],
                ]),
                cloudflareApiRecord([
                    'id' => 'rec-https',
                    'type' => 'HTTPS',
                    'name' => 'example.com',
                    'content' => '1 . alpn="h2"',
                    'ttl' => 300,
                ]),
            ], [
                'result_info' => ['page' => 1, 'per_page' => 5000, 'total_pages' => 1, 'count' => 6, 'total_count' => 6],
            ])),
        ]);

        $records = $this->connector->listRecords();

        expect($records)->toHaveCount(5)
            ->and($records->pluck('externalId')->all())->not->toContain('rec-https');

        /** @var RemoteRecord $a */
        $a = $records->firstWhere('externalId', 'rec-a');
        expect($a->type)->toBe('A')
            ->and($a->name)->toBe('www.example.com')
            ->and($a->content)->toBe('192.0.2.1')
            ->and($a->ttl)->toBeNull() // ttl 1 (auto) normalizes to null
            ->and($a->priority)->toBeNull()
            ->and($a->proxied)->toBeTrue();

        $mx = $records->firstWhere('externalId', 'rec-mx');
        expect($mx->ttl)->toBe(3600)
            ->and($mx->priority)->toBe(10)
            ->and($mx->proxied)->toBeFalse();

        $txt = $records->firstWhere('externalId', 'rec-txt');
        expect($txt->content)->toBe('v=spf1 -all');

        $srv = $records->firstWhere('externalId', 'rec-srv');
        expect($srv->content)->toBe('60 5060 sip.example.com')
            ->and($srv->priority)->toBe(5);

        $caa = $records->firstWhere('externalId', 'rec-caa');
        expect($caa->content)->toBe('0 issue "letsencrypt.org"');
    });

    it('paginates when total_pages is greater than 1', function () {
        Http::fake([
            $this->recordsUrl.'*' => Http::sequence()
                ->push(cloudflareEnvelope(
                    [cloudflareApiRecord(['id' => 'rec-page-1'])],
                    ['result_info' => ['page' => 1, 'per_page' => 5000, 'total_pages' => 2]],
                ))
                ->push(cloudflareEnvelope(
                    [cloudflareApiRecord(['id' => 'rec-page-2'])],
                    ['result_info' => ['page' => 2, 'per_page' => 5000, 'total_pages' => 2]],
                )),
        ]);

        $records = $this->connector->listRecords();

        expect($records->pluck('externalId')->all())->toBe(['rec-page-1', 'rec-page-2']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->data()['page'] == 1);
        Http::assertSent(fn (Request $request) => $request->data()['page'] == 2);
    });

    it('throws a ConnectorException when listing fails', function () {
        Http::fake([
            $this->recordsUrl.'*' => Http::response(cloudflareEnvelope(null, [
                'success' => false,
                'errors' => [['code' => 7003, 'message' => 'Could not route to /zones/bad']],
            ]), 404),
        ]);

        expect(fn () => $this->connector->listRecords())
            ->toThrow(ConnectorException::class, '[7003]');
    });
});

describe('testConnection', function () {
    it('verifies the token and fetches the zone on success', function () {
        Http::fake([
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(
                cloudflareEnvelope(['id' => 'token-1', 'status' => 'active']),
            ),
            'https://api.cloudflare.com/client/v4/zones/zone-abc-123' => Http::response(
                cloudflareEnvelope(['id' => 'zone-abc-123', 'name' => 'example.com', 'status' => 'active']),
            ),
        ]);

        $result = $this->connector->testConnection();

        expect($result->ok)->toBeTrue()
            ->and($result->message)->toBe('Connected to zone example.com')
            ->and($result->details)->toBe(['zone' => 'example.com', 'status' => 'active']);
    });

    it('fails with the Cloudflare error message when the token is invalid', function () {
        Http::fake([
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(cloudflareEnvelope(null, [
                'success' => false,
                'errors' => [['code' => 1000, 'message' => 'Invalid API Token']],
            ]), 401),
        ]);

        $result = $this->connector->testConnection();

        expect($result->ok)->toBeFalse()
            ->and($result->message)->toContain('Invalid API Token');
    });

    it('fails when the zone cannot be fetched', function () {
        Http::fake([
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response(
                cloudflareEnvelope(['id' => 'token-1', 'status' => 'active']),
            ),
            'https://api.cloudflare.com/client/v4/zones/zone-abc-123' => Http::response(cloudflareEnvelope(null, [
                'success' => false,
                'errors' => [['code' => 7003, 'message' => 'Could not route to /zones/zone-abc-123']],
            ]), 404),
        ]);

        $result = $this->connector->testConnection();

        expect($result->ok)->toBeFalse()
            ->and($result->message)->toContain('Zone lookup failed');
    });
});

describe('static metadata', function () {
    it('exposes type, display name, supported types and capabilities', function () {
        expect(CloudflareConnector::type())->toBe('cloudflare')
            ->and(CloudflareConnector::displayName())->toBe('Cloudflare')
            ->and(CloudflareConnector::supportedRecordTypes())->toBe(['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'CAA', 'PTR']);

        $capabilities = CloudflareConnector::capabilities();
        expect($capabilities->supportsProxied)->toBeTrue()
            ->and($capabilities->supportsTtl)->toBeTrue()
            ->and($capabilities->supportsPriority)->toBeTrue()
            ->and($capabilities->minTtl)->toBe(60)
            ->and($capabilities->maxTtl)->toBe(86400);

        $keys = array_map(fn ($field) => $field->key, CloudflareConnector::configSchema());
        expect($keys)->toBe(['api_token', 'zone_id']);
    });
});
