<?php

use App\Connectors\DTOs\RemoteRecord;
use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\RecordNotFoundException;
use App\Connectors\TechnitiumConnector;
use App\Enums\RecordType;
use App\Models\DnsEntry;
use App\Models\DnsZone;
use App\Models\Provider;
use App\Models\ZoneProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const TECHNITIUM_BASE = 'https://technitium.internal:53443';

function technitiumError(string $message): array
{
    return ['status' => 'error', 'errorMessage' => $message, 'stackTrace' => 'DnsWebServiceException: ...'];
}

function technitiumZonesList(array $zoneNames, ?int $totalZones = null): array
{
    return [
        'response' => [
            'pageNumber' => 1,
            'totalPages' => 1,
            'totalZones' => $totalZones ?? count($zoneNames),
            'zones' => array_map(fn (string $name) => [
                'name' => $name,
                'type' => 'Primary',
                'internal' => false,
                'disabled' => false,
            ], $zoneNames),
        ],
        'status' => 'ok',
    ];
}

function technitiumApiRecord(string $type, string $name, array $rData, int $ttl = 3600): array
{
    return [
        'disabled' => false,
        'name' => $name,
        'type' => $type,
        'ttl' => $ttl,
        'rData' => $rData,
        'dnssecStatus' => 'Unknown',
    ];
}

function technitiumRecordEnvelope(string $key, array $record): array
{
    return [
        'response' => [
            'zone' => ['name' => 'example.com', 'type' => 'Primary', 'internal' => false, 'disabled' => false],
            $key => $record,
        ],
        'status' => 'ok',
    ];
}

function technitiumAdded(array $overrides = []): array
{
    return technitiumRecordEnvelope('addedRecord', array_merge(
        technitiumApiRecord('A', 'www.example.com', ['ipAddress' => '192.0.2.1']),
        $overrides,
    ));
}

function technitiumRecordsList(array $records): array
{
    return [
        'response' => [
            'zone' => ['name' => 'example.com', 'type' => 'Primary', 'internal' => false, 'disabled' => false],
            'records' => $records,
        ],
        'status' => 'ok',
    ];
}

beforeEach(function () {
    $this->zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->provider = Provider::factory()->technitium()->create([
        'config' => ['base_url' => TECHNITIUM_BASE, 'api_token' => 'test-token', 'verify_tls' => true],
    ]);

    $this->zoneProvider = ZoneProvider::factory()->create([
        'dns_zone_id' => $this->zone->id,
        'provider_id' => $this->provider->id,
        'config' => null,
    ]);

    $this->connector = new TechnitiumConnector($this->provider, $this->zoneProvider);

    $this->zonesListUrl = TECHNITIUM_BASE.'/api/zones/list*';
    $this->addUrl = TECHNITIUM_BASE.'/api/zones/records/add*';
    $this->getUrl = TECHNITIUM_BASE.'/api/zones/records/get*';
    $this->updateUrl = TECHNITIUM_BASE.'/api/zones/records/update*';
    $this->deleteUrl = TECHNITIUM_BASE.'/api/zones/records/delete*';
});

describe('createRecord', function () {
    it('adds the FQDN A record scoped to the zone and returns the tuple external id', function () {
        Http::fake([$this->addUrl => Http::response(technitiumAdded())]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => 'www',
            'type' => RecordType::A,
            'content' => '192.0.2.1',
            'ttl' => null,
        ]);

        expect($this->connector->createRecord($entry))
            ->toBe('{"type":"A","name":"www.example.com","ipAddress":"192.0.2.1"}');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), TECHNITIUM_BASE.'/api/zones/records/add?')
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['domain'] === 'www.example.com'
                && $request['zone'] === 'example.com'
                && $request['type'] === 'A'
                && $request['ipAddress'] === '192.0.2.1'
                && $request['ttl'] === 3600; // null (auto) TTL pushes the connector default
        });
    });

    it('expands the apex entry name (@) to the zone FQDN', function () {
        Http::fake([$this->addUrl => Http::response(technitiumAdded(['name' => 'example.com']))]);

        $entry = DnsEntry::factory()->apex()->for($this->zone, 'zone')->create(['content' => '192.0.2.9']);

        $this->connector->createRecord($entry);

        Http::assertSent(fn (Request $request) => $request['domain'] === 'example.com');
    });

    it('sends exchange and preference for MX records, defaulting the preference to 10', function () {
        Http::fake([$this->addUrl => Http::response(technitiumAdded(['type' => 'MX']))]);

        $entry = DnsEntry::factory()->mx()->for($this->zone, 'zone')->create([
            'name' => '@',
            'ttl' => 3600,
            'priority' => null,
        ]);

        expect($this->connector->createRecord($entry))
            ->toBe('{"type":"MX","name":"example.com","exchange":"mail.example.com","preference":10}');

        Http::assertSent(function (Request $request) {
            return $request['type'] === 'MX'
                && $request['exchange'] === 'mail.example.com'
                && $request['preference'] === 10
                && ! array_key_exists('priority', $request->data());
        });
    });

    it('sends TXT content raw via the text param, without quoting', function () {
        Http::fake([$this->addUrl => Http::response(technitiumAdded(['type' => 'TXT']))]);

        $entry = DnsEntry::factory()->apex()->for($this->zone, 'zone')->create([
            'type' => RecordType::TXT,
            'content' => 'v=spf1 include:_spf.example.com -all',
        ]);

        $this->connector->createRecord($entry);

        Http::assertSent(fn (Request $request) => $request['text'] === 'v=spf1 include:_spf.example.com -all');
    });

    it('splits SRV content ("weight port target") into the per-part params', function () {
        Http::fake([$this->addUrl => Http::response(technitiumAdded(['type' => 'SRV']))]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => '_sip._tcp',
            'type' => RecordType::SRV,
            'content' => '60 5060 sip.example.com',
            'priority' => 5,
        ]);

        $this->connector->createRecord($entry);

        Http::assertSent(function (Request $request) {
            return $request['type'] === 'SRV'
                && $request['domain'] === '_sip._tcp.example.com'
                && $request['priority'] === 5
                && $request['weight'] === 60
                && $request['port'] === 5060
                && $request['target'] === 'sip.example.com';
        });
    });

    it('throws a ConnectorException for malformed SRV content', function () {
        Http::fake();

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => '_sip._tcp',
            'type' => RecordType::SRV,
            'content' => 'not-a-valid-srv-content',
        ]);

        expect(fn () => $this->connector->createRecord($entry))
            ->toThrow(ConnectorException::class, 'weight port target');

        Http::assertNothingSent();
    });

    it('splits CAA content into flags, tag and value params', function () {
        Http::fake([$this->addUrl => Http::response(technitiumAdded(['type' => 'CAA']))]);

        $entry = DnsEntry::factory()->apex()->for($this->zone, 'zone')->create([
            'type' => RecordType::CAA,
            'content' => '0 issue "letsencrypt.org"',
        ]);

        $this->connector->createRecord($entry);

        Http::assertSent(function (Request $request) {
            return $request['type'] === 'CAA'
                && $request['flags'] === 0
                && $request['tag'] === 'issue'
                && $request['value'] === 'letsencrypt.org';
        });
    });

    it('sends the explicit ttl and the comment when set', function () {
        Http::fake([$this->addUrl => Http::response(technitiumAdded(['type' => 'CNAME']))]);

        $entry = DnsEntry::factory()->cname('target.example.com')->for($this->zone, 'zone')->create([
            'name' => 'alias',
            'ttl' => 300,
            'comment' => 'managed by dns-manager',
        ]);

        $this->connector->createRecord($entry);

        Http::assertSent(function (Request $request) {
            return $request['cname'] === 'target.example.com'
                && $request['ttl'] === 300
                && $request['comments'] === 'managed by dns-manager';
        });
    });

    it('adopts an already-existing record by overwriting the record set', function () {
        Http::fake([
            $this->addUrl => Http::sequence()
                ->push(technitiumError('Cannot add record: record already exists.'))
                ->push(technitiumAdded()),
        ]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => 'www',
            'type' => RecordType::A,
            'content' => '192.0.2.1',
        ]);

        expect($this->connector->createRecord($entry))
            ->toBe('{"type":"A","name":"www.example.com","ipAddress":"192.0.2.1"}');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => ! array_key_exists('overwrite', $request->data()));
        Http::assertSent(fn (Request $request) => ($request->data()['overwrite'] ?? null) === 'true'
            && $request['ipAddress'] === '192.0.2.1');
    });

    it('fails on an existing record when adoption is disabled', function () {
        Http::fake([$this->addUrl => Http::response(technitiumError('Cannot add record: record already exists.'))]);

        $provider = Provider::factory()->technitium()->create([
            'config' => ['base_url' => TECHNITIUM_BASE, 'api_token' => 'test-token', 'adopt_existing' => false],
        ]);
        $zoneProvider = ZoneProvider::factory()->create([
            'dns_zone_id' => $this->zone->id,
            'provider_id' => $provider->id,
            'config' => null,
        ]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create(['name' => 'www']);

        expect(fn () => (new TechnitiumConnector($provider, $zoneProvider))->createRecord($entry))
            ->toThrow(ConnectorException::class, 'adoption is disabled');

        Http::assertSentCount(1);
    });

    it('throws a ConnectorException with the errorMessage from a status:error envelope', function () {
        Http::fake([$this->addUrl => Http::response(technitiumError('Invalid zone name.'))]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create(['name' => 'www']);

        expect(fn () => $this->connector->createRecord($entry))
            ->toThrow(ConnectorException::class, 'Invalid zone name.');
    });

    it('throws a ConnectorException on an HTTP-level failure', function () {
        Http::fake([$this->addUrl => Http::response('Server Error', 500)]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create(['name' => 'www']);

        expect(fn () => $this->connector->createRecord($entry))
            ->toThrow(ConnectorException::class, 'HTTP 500');
    });
});

describe('zone context requirement', function () {
    it('throws a ConnectorException for record operations without a zone attachment', function () {
        Http::fake();

        $connector = new TechnitiumConnector($this->provider);
        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create(['name' => 'www']);
        $externalId = '{"type":"A","name":"www.example.com","ipAddress":"192.0.2.1"}';

        expect(fn () => $connector->listRecords())
            ->toThrow(ConnectorException::class, 'requires a zone attachment')
            ->and(fn () => $connector->createRecord($entry))
            ->toThrow(ConnectorException::class, 'requires a zone attachment')
            ->and(fn () => $connector->updateRecord($entry, $externalId))
            ->toThrow(ConnectorException::class, 'requires a zone attachment')
            ->and(fn () => $connector->deleteRecord($externalId))
            ->toThrow(ConnectorException::class, 'requires a zone attachment');

        Http::assertNothingSent();
    });
});

describe('updateRecord', function () {
    it('updates an A record in one call using old and new values', function () {
        Http::fake([$this->updateUrl => Http::response(technitiumRecordEnvelope('updatedRecord', technitiumApiRecord('A', 'www.example.com', ['ipAddress' => '192.0.2.9'])))]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => 'www',
            'type' => RecordType::A,
            'content' => '192.0.2.9',
            'ttl' => 300,
        ]);

        $newId = $this->connector->updateRecord($entry, '{"type":"A","name":"www.example.com","ipAddress":"192.0.2.1"}');

        expect($newId)->toBe('{"type":"A","name":"www.example.com","ipAddress":"192.0.2.9"}');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), TECHNITIUM_BASE.'/api/zones/records/update?')
                && $request['domain'] === 'www.example.com'
                && $request['zone'] === 'example.com'
                && $request['type'] === 'A'
                && $request['ipAddress'] === '192.0.2.1'
                && $request['newIpAddress'] === '192.0.2.9'
                && $request['ttl'] === 300
                && ! array_key_exists('newDomain', $request->data());
        });
    });

    it('sends newDomain when the entry was renamed', function () {
        Http::fake([$this->updateUrl => Http::response(technitiumRecordEnvelope('updatedRecord', technitiumApiRecord('A', 'app.example.com', ['ipAddress' => '192.0.2.1'])))]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => 'app',
            'type' => RecordType::A,
            'content' => '192.0.2.1',
        ]);

        $this->connector->updateRecord($entry, '{"type":"A","name":"www.example.com","ipAddress":"192.0.2.1"}');

        Http::assertSent(fn (Request $request) => $request['domain'] === 'www.example.com'
            && $request['newDomain'] === 'app.example.com');
    });

    it('pairs old and new values for MX records', function () {
        Http::fake([$this->updateUrl => Http::response(technitiumRecordEnvelope('updatedRecord', technitiumApiRecord('MX', 'example.com', ['exchange' => 'mx2.example.com', 'preference' => 20])))]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => '@',
            'type' => RecordType::MX,
            'content' => 'mx2.example.com',
            'priority' => 20,
        ]);

        $this->connector->updateRecord($entry, '{"type":"MX","name":"example.com","exchange":"mail.example.com","preference":10}');

        Http::assertSent(function (Request $request) {
            return $request['exchange'] === 'mail.example.com'
                && $request['newExchange'] === 'mx2.example.com'
                && $request['preference'] === 10
                && $request['newPreference'] === 20;
        });
    });

    it('retargets a CNAME with the cname param alone — the name identifies it', function () {
        Http::fake([$this->updateUrl => Http::response(technitiumRecordEnvelope('updatedRecord', technitiumApiRecord('CNAME', 'alias.example.com', ['cname' => 'new-target.example.com'])))]);

        $entry = DnsEntry::factory()->cname('new-target.example.com')->for($this->zone, 'zone')->create(['name' => 'alias']);

        $this->connector->updateRecord($entry, '{"type":"CNAME","name":"alias.example.com","cname":"old-target.example.com"}');

        Http::assertSent(fn (Request $request) => $request['cname'] === 'new-target.example.com'
            && ! array_key_exists('newCname', $request->data()));
    });

    it('throws RecordNotFoundException when the old record was removed out-of-band', function () {
        Http::fake([$this->updateUrl => Http::response(technitiumError('Cannot update record: no such record exists.'))]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => 'www',
            'type' => RecordType::A,
            'content' => '192.0.2.9',
        ]);

        expect(fn () => $this->connector->updateRecord($entry, '{"type":"A","name":"www.example.com","ipAddress":"192.0.2.1"}'))
            ->toThrow(RecordNotFoundException::class, 'no such record exists');
    });

    it('falls back to delete + create when the record type changed', function () {
        Http::fake([
            $this->deleteUrl => Http::response(['response' => [], 'status' => 'ok']),
            $this->addUrl => Http::response(technitiumAdded()),
        ]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => 'www',
            'type' => RecordType::A,
            'content' => '192.0.2.1',
        ]);

        $newId = $this->connector->updateRecord($entry, '{"type":"CNAME","name":"www.example.com","cname":"old-target.example.com"}');

        expect($newId)->toBe('{"type":"A","name":"www.example.com","ipAddress":"192.0.2.1"}');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/zones/records/delete')
            && $request['type'] === 'CNAME');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/zones/records/add')
            && $request['type'] === 'A');
    });
});

describe('deleteRecord', function () {
    it('deletes the record identified by the decoded external id', function () {
        Http::fake([$this->deleteUrl => Http::response(['response' => [], 'status' => 'ok'])]);

        $this->connector->deleteRecord('{"type":"A","name":"www.example.com","ipAddress":"192.0.2.1"}');

        Http::assertSent(function (Request $request) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), TECHNITIUM_BASE.'/api/zones/records/delete?')
                && $request['domain'] === 'www.example.com'
                && $request['zone'] === 'example.com'
                && $request['type'] === 'A'
                && $request['ipAddress'] === '192.0.2.1';
        });
    });

    it('identifies an MX record by exchange and preference', function () {
        Http::fake([$this->deleteUrl => Http::response(['response' => [], 'status' => 'ok'])]);

        $this->connector->deleteRecord('{"type":"MX","name":"example.com","exchange":"mail.example.com","preference":10}');

        Http::assertSent(fn (Request $request) => $request['exchange'] === 'mail.example.com'
            && $request['preference'] === 10);
    });

    it('sends no value param for a CNAME — domain and type identify it', function () {
        Http::fake([$this->deleteUrl => Http::response(['response' => [], 'status' => 'ok'])]);

        $this->connector->deleteRecord('{"type":"CNAME","name":"alias.example.com","cname":"target.example.com"}');

        Http::assertSent(fn (Request $request) => $request['domain'] === 'alias.example.com'
            && $request['type'] === 'CNAME'
            && ! array_key_exists('cname', $request->data()));
    });

    it('treats an already-missing record as success', function () {
        Http::fake([$this->deleteUrl => Http::response(technitiumError('Cannot delete record: no such record exists.'))]);

        $this->connector->deleteRecord('{"type":"A","name":"gone.example.com","ipAddress":"192.0.2.1"}');

        Http::assertSentCount(1);
    });

    it('throws on other envelope errors', function () {
        Http::fake([$this->deleteUrl => Http::response(technitiumError('Access was denied.'))]);

        expect(fn () => $this->connector->deleteRecord('{"type":"A","name":"www.example.com","ipAddress":"192.0.2.1"}'))
            ->toThrow(ConnectorException::class, 'Access was denied.');
    });
});

describe('listRecords', function () {
    it('lists the whole zone and maps supported records to RemoteRecords', function () {
        Http::fake([
            $this->getUrl => Http::response(technitiumRecordsList([
                technitiumApiRecord('A', 'www.example.com', ['ipAddress' => '192.0.2.1'], ttl: 300),
                technitiumApiRecord('MX', 'example.com', ['exchange' => 'mail.example.com', 'preference' => 10], ttl: 900),
                technitiumApiRecord('TXT', 'example.com', ['text' => 'v=spf1 -all'], ttl: 300),
                technitiumApiRecord('SRV', '_sip._tcp.example.com', ['priority' => 5, 'weight' => 60, 'port' => 5060, 'target' => 'sip.example.com'], ttl: 300),
                technitiumApiRecord('CAA', 'example.com', ['flags' => 0, 'tag' => 'issue', 'value' => 'letsencrypt.org'], ttl: 300),
                technitiumApiRecord('SOA', 'example.com', ['primaryNameServer' => 'ns1.example.com', 'serial' => 35], ttl: 900),
                technitiumApiRecord('RRSIG', 'example.com', ['typeCovered' => 'SOA'], ttl: 900),
            ])),
        ]);

        $records = $this->connector->listRecords();

        expect($records)->toHaveCount(5)
            ->and($records->pluck('type')->all())->not->toContain('SOA', 'RRSIG');

        Http::assertSent(fn (Request $request) => $request['domain'] === 'example.com'
            && $request['zone'] === 'example.com'
            && $request['listZone'] === 'true');

        /** @var RemoteRecord $a */
        $a = $records->firstWhere('type', 'A');
        expect($a->name)->toBe('www.example.com')
            ->and($a->content)->toBe('192.0.2.1')
            ->and($a->ttl)->toBe(300)
            ->and($a->externalId)->toBe('{"type":"A","name":"www.example.com","ipAddress":"192.0.2.1"}');

        $mx = $records->firstWhere('type', 'MX');
        expect($mx->content)->toBe('mail.example.com')
            ->and($mx->priority)->toBe(10)
            ->and($mx->externalId)->toBe('{"type":"MX","name":"example.com","exchange":"mail.example.com","preference":10}');

        $txt = $records->firstWhere('type', 'TXT');
        expect($txt->content)->toBe('v=spf1 -all');

        $srv = $records->firstWhere('type', 'SRV');
        expect($srv->content)->toBe('60 5060 sip.example.com')
            ->and($srv->priority)->toBe(5);

        $caa = $records->firstWhere('type', 'CAA');
        expect($caa->content)->toBe('0 issue "letsencrypt.org"');
    });

    it('reports the connector default TTL (3600) as auto so pushed entries never drift on TTL', function () {
        Http::fake([
            $this->getUrl => Http::response(technitiumRecordsList([
                technitiumApiRecord('A', 'www.example.com', ['ipAddress' => '192.0.2.1'], ttl: 3600),
            ])),
        ]);

        $record = $this->connector->listRecords()->sole();

        expect($record->ttl)->toBeNull();

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => 'www',
            'type' => RecordType::A,
            'content' => '192.0.2.1',
            'ttl' => null,
        ]);

        // Round trip: the external id matches what createRecord() returns
        // and the record matches the entry for drift purposes.
        expect($record->externalId)->toBe('{"type":"A","name":"www.example.com","ipAddress":"192.0.2.1"}')
            ->and($record->matches($entry, TechnitiumConnector::capabilities()))->toBeTrue();
    });

    it('does not flag TTL drift when the entry pins the connector default (3600) explicitly', function () {
        Http::fake([
            $this->getUrl => Http::response(technitiumRecordsList([
                technitiumApiRecord('A', 'www.example.com', ['ipAddress' => '192.0.2.1'], ttl: 3600),
            ])),
        ]);

        // Remote 3600 reads back as auto (null) — an entry that says 3600
        // explicitly is still the same record, not drift.
        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => 'www',
            'type' => RecordType::A,
            'content' => '192.0.2.1',
            'ttl' => 3600,
        ]);

        $record = $this->connector->listRecords()->sole();

        expect($record->ttl)->toBeNull()
            ->and($record->matches($entry, TechnitiumConnector::capabilities()))->toBeTrue()
            ->and($record->diff($entry, TechnitiumConnector::capabilities()))->toBe([]);
    });

    it('diffs a drifted record field by field (tracked vs actual)', function () {
        Http::fake([
            $this->getUrl => Http::response(technitiumRecordsList([
                technitiumApiRecord('A', 'www.example.com', ['ipAddress' => '198.51.100.7'], ttl: 300),
            ])),
        ]);

        $entry = DnsEntry::factory()->for($this->zone, 'zone')->create([
            'name' => 'www',
            'type' => RecordType::A,
            'content' => '192.0.2.1',
            'ttl' => null,
        ]);

        expect($this->connector->listRecords()->sole()->diff($entry, TechnitiumConnector::capabilities()))->toBe([
            ['field' => 'content', 'tracked' => '192.0.2.1', 'actual' => '198.51.100.7'],
            ['field' => 'ttl', 'tracked' => null, 'actual' => 300],
        ]);
    });

    it('decodes TXT character strings when no plain text field is present', function () {
        Http::fake([
            $this->getUrl => Http::response(technitiumRecordsList([
                technitiumApiRecord('TXT', 'example.com', [
                    'characterStringsBase64' => [base64_encode('v=spf1 '), base64_encode('-all')],
                ]),
            ])),
        ]);

        expect($this->connector->listRecords()->sole()->content)->toBe('v=spf1 -all');
    });

    it('throws a ConnectorException when the zone listing errors', function () {
        Http::fake([$this->getUrl => Http::response(technitiumError('No such authoritative zone was found: example.com'))]);

        expect(fn () => $this->connector->listRecords())
            ->toThrow(ConnectorException::class, 'No such authoritative zone was found');
    });
});

describe('testConnection', function () {
    it('validates the token by listing zones and reports the zone count', function () {
        Http::fake([$this->zonesListUrl => Http::response(technitiumZonesList(['example.com'], totalZones: 12))]);

        $result = (new TechnitiumConnector($this->provider))->testConnection();

        expect($result->ok)->toBeTrue()
            ->and($result->message)->toBe('API token is valid — 12 zones hosted.')
            ->and($result->details)->toBe(['zones' => 12]);

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), TECHNITIUM_BASE.'/api/zones/list?')
            && $request['zonesPerPage'] === 1);
    });

    it('fails when the token is rejected', function () {
        Http::fake([$this->zonesListUrl => Http::response(['status' => 'invalid-token'])]);

        $result = $this->connector->testConnection();

        expect($result->ok)->toBeFalse()
            ->and($result->message)->toContain('Token check failed')
            ->and($result->message)->toContain('invalid-token');
    });

    it('fails gracefully when the server is unreachable', function () {
        Http::fake([$this->zonesListUrl => Http::failedConnection('Connection refused')]);

        $result = $this->connector->testConnection();

        expect($result->ok)->toBeFalse()
            ->and($result->message)->toContain('Could not reach Technitium');
    });
});

describe('testZone', function () {
    it('succeeds when the zone exists on the server (case-insensitively)', function () {
        Http::fake([$this->zonesListUrl => Http::response(technitiumZonesList(['Example.COM', 'other.net']))]);

        $result = $this->connector->testZone();

        expect($result->ok)->toBeTrue()
            ->and($result->message)->toBe('Connected to zone example.com')
            ->and($result->details)->toBe(['zone' => 'example.com']);

        Http::assertSent(fn (Request $request) => $request['filterName'] === 'example.com');
    });

    it('fails when the zone does not exist on the server', function () {
        Http::fake([$this->zonesListUrl => Http::response(technitiumZonesList(['other.net']))]);

        $result = $this->connector->testZone();

        expect($result->ok)->toBeFalse()
            ->and($result->message)->toBe('Zone example.com does not exist on this Technitium server');
    });

    it('fails with the API error message when the lookup errors', function () {
        Http::fake([$this->zonesListUrl => Http::response(['status' => 'invalid-token'])]);

        $result = $this->connector->testZone();

        expect($result->ok)->toBeFalse()
            ->and($result->message)->toContain('invalid-token');
    });

    it('throws a ConnectorException without a zone attachment', function () {
        Http::fake();

        expect(fn () => (new TechnitiumConnector($this->provider))->testZone())
            ->toThrow(ConnectorException::class, 'Technitium operation requires a zone attachment');

        Http::assertNothingSent();
    });
});

describe('discoverZoneConfig', function () {
    it('returns an empty config when the zone exists — nothing per-zone to store', function () {
        Http::fake([$this->zonesListUrl => Http::response(technitiumZonesList(['example.com']))]);

        expect((new TechnitiumConnector($this->provider))->discoverZoneConfig('example.com'))->toBe([]);
    });

    it('returns null when the zone is not hosted', function () {
        Http::fake([$this->zonesListUrl => Http::response(technitiumZonesList(['other.net']))]);

        expect((new TechnitiumConnector($this->provider))->discoverZoneConfig('example.com'))->toBeNull();
    });

    it('returns null when the API call fails', function () {
        Http::fake([$this->zonesListUrl => Http::response(['status' => 'invalid-token'])]);

        expect((new TechnitiumConnector($this->provider))->discoverZoneConfig('example.com'))->toBeNull();
    });
});

it('operates normally with TLS verification disabled', function () {
    Http::fake([$this->zonesListUrl => Http::response(technitiumZonesList(['example.com']))]);

    $provider = Provider::factory()->technitium()->create([
        'config' => ['base_url' => TECHNITIUM_BASE, 'api_token' => 'test-token', 'verify_tls' => false],
    ]);

    // withoutVerifying() is a Guzzle transport option that Http::fake() does not
    // expose, so we only assert the connector works end-to-end with the flag set.
    expect((new TechnitiumConnector($provider))->testConnection()->ok)->toBeTrue();
});

describe('static metadata', function () {
    it('exposes type, display name, supported types and capabilities', function () {
        expect(TechnitiumConnector::type())->toBe('technitium')
            ->and(TechnitiumConnector::displayName())->toBe('Technitium')
            ->and(TechnitiumConnector::supportedRecordTypes())->toBe(['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'NS', 'CAA', 'PTR']);

        $capabilities = TechnitiumConnector::capabilities();
        expect($capabilities->supportsProxied)->toBeFalse()
            ->and($capabilities->supportsTtl)->toBeTrue()
            ->and($capabilities->supportsPriority)->toBeTrue()
            ->and($capabilities->supportsZones)->toBeTrue()
            ->and($capabilities->minTtl)->toBeNull()
            ->and($capabilities->maxTtl)->toBeNull();
    });

    it('declares its credential schema and an empty zone config schema — the zone name is the address', function () {
        $keys = array_map(fn ($field) => $field->key, TechnitiumConnector::configSchema());
        expect($keys)->toBe(['base_url', 'api_token', 'verify_tls', 'adopt_existing']);

        expect(TechnitiumConnector::zoneConfigSchema())->toBe([]);
    });
});
