<?php

declare(strict_types = 1);

use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Models\Provider;
use Illuminate\Support\Str;
use Illuminate\Support\Sleep;
use App\Connectors\PiholeConnector;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use App\Connectors\DTOs\RemoteRecord;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Promise\PromiseInterface;
use App\Connectors\Exceptions\ConnectorException;

const PIHOLE_BASE = 'https://pihole.internal';

function piholeZone(string $name = 'example.com'): DnsZone
{
    return DnsZone::factory()->create(['name' => $name]);
}

function piholeProvider(array $config = []): Provider
{
    return Provider::factory()->pihole()->create([
        'config' => array_merge([
            'base_url' => PIHOLE_BASE,
            'app_password' => 'super-secret',
            'verify_tls' => true,
        ], $config),
    ]);
}

/**
 * Fake the full Pi-hole v6 API. $overrides maps "METHOD /path" prefixes to
 * responses; anything not overridden gets a realistic default.
 */
function fakePihole(array $overrides = []): void
{
    Http::fake(function (Request $request) use ($overrides) {
        $key = $request->method() . ' /' . ltrim(Str::after($request->url(), PIHOLE_BASE), '/');

        foreach ($overrides as $prefix => $response) {
            if (str_starts_with($key, $prefix)) {
                return $response;
            }
        }

        return match (true) {
            $key === 'POST /api/auth' => Http::response([
                'session' => ['valid' => true, 'sid' => 'test-sid', 'csrf' => 'test-csrf', 'validity' => 300],
                'took' => 0.1,
            ]),
            $key === 'DELETE /api/auth' => Http::response(null, 204),
            $key === 'GET /api/info/version' => Http::response([
                'version' => ['core' => ['local' => ['version' => 'v6.1']]],
                'took' => 0.1,
            ]),
            $key === 'GET /api/config/dns/hosts' => Http::response([
                'config' => ['dns' => ['hosts' => []]],
                'took' => 0.1,
            ]),
            $key === 'GET /api/config/dns/cnameRecords' => Http::response([
                'config' => ['dns' => ['cnameRecords' => []]],
                'took' => 0.1,
            ]),
            str_starts_with($key, 'PUT /api/config/dns/') => Http::response(['took' => 0.1], 201),
            str_starts_with($key, 'DELETE /api/config/dns/') => Http::response(null, 204),
            default => Http::response([
                'error' => ['key' => 'not_found', 'message' => 'Not found', 'hint' => null],
                'took' => 0.1,
            ], 404),
        };
    });
}

function piholeError(string $key, string $message, int $status, ?string $hint = null): PromiseInterface
{
    return Http::response([
        'error' => ['key' => $key, 'message' => $message, 'hint' => $hint],
        'took' => 0.1,
    ], $status);
}

it('creates an A record via an encoded FQDN hosts PUT inside one session', function () {
    fakePihole();

    $connector = new PiholeConnector(piholeProvider());
    $entry = DnsEntry::factory()->for(piholeZone(), 'zone')->create([
        'name' => 'host',
        'content' => '192.168.1.10',
    ]);

    $externalId = $connector->createRecord($entry);

    expect($externalId)->toBe('192.168.1.10 host.example.com');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === PIHOLE_BASE . '/api/config/dns/hosts/192.168.1.10%20host.example.com'
        && $request->hasHeader('X-FTL-SID', 'test-sid'));

    // Authenticated first, then released the session afterwards.
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === PIHOLE_BASE . '/api/auth');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === PIHOLE_BASE . '/api/auth');
});

it('creates a CNAME with TTL as an encoded fqdn,target,ttl entry', function () {
    fakePihole();

    $connector = new PiholeConnector(piholeProvider());
    $entry = DnsEntry::factory()->cname('target.example.com')->for(piholeZone(), 'zone')->create([
        'name' => 'alias',
        'ttl' => 3600,
    ]);

    expect($connector->createRecord($entry))->toBe('alias.example.com,target.example.com,3600');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === PIHOLE_BASE . '/api/config/dns/cnameRecords/alias.example.com%2Ctarget.example.com%2C3600');
});

it('omits the TTL from a CNAME entry when none is set', function () {
    fakePihole();

    $connector = new PiholeConnector(piholeProvider());
    $entry = DnsEntry::factory()->cname('target.example.com')->for(piholeZone(), 'zone')->create([
        'name' => 'alias',
        'ttl' => null,
    ]);

    expect($connector->createRecord($entry))->toBe('alias.example.com,target.example.com');

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === PIHOLE_BASE . '/api/config/dns/cnameRecords/alias.example.com%2Ctarget.example.com');
});

it('treats a 400 already-exists response as a successful create', function () {
    fakePihole([
        'PUT /api/config/dns/hosts/' => piholeError('bad_request', 'Item already present', 400, 'Uniqueness of items is enforced'),
    ]);

    $connector = new PiholeConnector(piholeProvider());
    $entry = DnsEntry::factory()->for(piholeZone(), 'zone')->create([
        'name' => 'host',
        'content' => '192.168.1.10',
    ]);

    expect($connector->createRecord($entry))->toBe('192.168.1.10 host.example.com');

    // The session is still released.
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === PIHOLE_BASE . '/api/auth');
});

it('throws on 400 already-exists when adoption is disabled', function () {
    fakePihole([
        'PUT /api/config/dns/hosts/' => piholeError('bad_request', 'Item already present', 400, 'Uniqueness of items is enforced'),
    ]);

    $connector = new PiholeConnector(piholeProvider(['adopt_existing' => false]));
    $entry = DnsEntry::factory()->for(piholeZone(), 'zone')->create([
        'name' => 'host',
        'content' => '192.168.1.10',
    ]);

    expect(fn () => $connector->createRecord($entry))
        ->toThrow(ConnectorException::class, 'adoption is disabled');
});

it('updates a record by deleting the old entry then putting the new one in a single session', function () {
    fakePihole();

    $connector = new PiholeConnector(piholeProvider());
    $entry = DnsEntry::factory()->for(piholeZone(), 'zone')->create([
        'name' => 'host',
        'content' => '192.168.1.20',
    ]);

    $externalId = $connector->updateRecord($entry, '192.168.1.10 host.example.com');

    expect($externalId)->toBe('192.168.1.20 host.example.com');

    $requests = collect(Http::recorded())->map(
        fn (array $pair) => $pair[0]->method() . ' ' . $pair[0]->url(),
    )->values();

    $deleteIndex = $requests->search('DELETE ' . PIHOLE_BASE . '/api/config/dns/hosts/192.168.1.10%20host.example.com');
    $putIndex = $requests->search('PUT ' . PIHOLE_BASE . '/api/config/dns/hosts/192.168.1.20%20host.example.com');

    expect($deleteIndex)->not->toBeFalse()
        ->and($putIndex)->not->toBeFalse()
        ->and($deleteIndex)->toBeLessThan($putIndex);

    // Exactly one auth session for the whole update.
    expect($requests->filter(fn (string $line) => $line === 'POST ' . PIHOLE_BASE . '/api/auth'))->toHaveCount(1);
});

it('tolerates a 404 when deleting a record that is already gone', function () {
    fakePihole([
        'DELETE /api/config/dns/hosts/' => piholeError('not_found', 'Item not found', 404),
    ]);

    $connector = new PiholeConnector(piholeProvider());

    $connector->deleteRecord('192.168.1.10 host.example.com');

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === PIHOLE_BASE . '/api/config/dns/hosts/192.168.1.10%20host.example.com');
});

it('lists and parses hosts and CNAME records including multi-hostname and IPv6 lines', function () {
    fakePihole([
        'GET /api/config/dns/hosts' => Http::response([
            'config' => ['dns' => ['hosts' => [
                '192.168.2.123 mymusicbox',
                '10.0.0.5 a.lan b.lan',
                'fd00::10 ipv6host.lan',
            ]]],
            'took' => 0.1,
        ]),
        'GET /api/config/dns/cnameRecords' => Http::response([
            'config' => ['dns' => ['cnameRecords' => [
                'alias.lan,target.lan',
                'h.lan,t.lan,3600',
            ]]],
            'took' => 0.1,
        ]),
    ]);

    $connector = new PiholeConnector(piholeProvider());

    $records = $connector->listRecords();

    expect($records)->toHaveCount(6)
        ->and($records)->each->toBeInstanceOf(RemoteRecord::class);

    [$music, $a, $b, $v6, $alias, $withTtl] = $records->all();

    expect($music->type)->toBe('A')
        ->and($music->name)->toBe('mymusicbox')
        ->and($music->content)->toBe('192.168.2.123')
        ->and($music->externalId)->toBe('192.168.2.123 mymusicbox')
        ->and($music->ttl)->toBeNull();

    // Multi-hostname line yields one record per hostname, sharing the raw line as external id.
    expect($a->name)->toBe('a.lan')
        ->and($b->name)->toBe('b.lan')
        ->and($a->content)->toBe('10.0.0.5')
        ->and($a->externalId)->toBe('10.0.0.5 a.lan b.lan')
        ->and($b->externalId)->toBe('10.0.0.5 a.lan b.lan');

    expect($v6->type)->toBe('AAAA')
        ->and($v6->name)->toBe('ipv6host.lan')
        ->and($v6->content)->toBe('fd00::10');

    expect($alias->type)->toBe('CNAME')
        ->and($alias->name)->toBe('alias.lan')
        ->and($alias->content)->toBe('target.lan')
        ->and($alias->ttl)->toBeNull()
        ->and($alias->externalId)->toBe('alias.lan,target.lan');

    expect($withTtl->type)->toBe('CNAME')
        ->and($withTtl->ttl)->toBe(3600)
        ->and($withTtl->externalId)->toBe('h.lan,t.lan,3600');
});

it('reports the Pi-hole version on a successful connection test', function () {
    fakePihole();

    $connector = new PiholeConnector(piholeProvider());

    $result = $connector->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->message)->toContain('v6.1')
        ->and($result->details)->toBe(['version' => 'v6.1']);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === PIHOLE_BASE . '/api/info/version');
});

it('returns a failure test result when the app password is rejected', function () {
    fakePihole([
        'POST /api/auth' => piholeError('unauthorized', 'Unauthorized', 401),
    ]);

    $connector = new PiholeConnector(piholeProvider());

    $result = $connector->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->message)->toContain('Unauthorized');
});

it('throws a ConnectorException when authentication fails during an operation', function () {
    fakePihole([
        'POST /api/auth' => piholeError('unauthorized', 'Unauthorized', 401),
    ]);

    $connector = new PiholeConnector(piholeProvider());

    $connector->listRecords();
})->throws(ConnectorException::class, 'Unauthorized');

it('throws a ConnectorException when the session is reported invalid despite a 200', function () {
    fakePihole([
        'POST /api/auth' => Http::response([
            'session' => ['valid' => false, 'sid' => null, 'validity' => -1],
            'took' => 0.1,
        ]),
    ]);

    $connector = new PiholeConnector(piholeProvider());
    $entry = DnsEntry::factory()->create();

    $connector->createRecord($entry);
})->throws(ConnectorException::class);

it('waits out the resolver-restart cooldown before the session that follows a CNAME write', function () {
    fakePihole();

    $connector = new PiholeConnector(piholeProvider());
    $entry = DnsEntry::factory()->cname('target.example.com')->for(piholeZone(), 'zone')->create([
        'name' => 'alias',
        'ttl' => null,
    ]);

    $connector->createRecord($entry);
    Sleep::assertNeverSlept();

    // The next session must give FTL time to restart before authenticating.
    $connector->listRecords();
    Sleep::assertSleptTimes(1);
});

it('does not pace sessions after hosts-only writes', function () {
    fakePihole();

    $connector = new PiholeConnector(piholeProvider());
    $entry = DnsEntry::factory()->for(piholeZone(), 'zone')->create([
        'name' => 'host',
        'content' => '192.168.1.10',
    ]);

    $connector->createRecord($entry);
    $connector->listRecords();

    Sleep::assertNeverSlept();
});

it('paces the next session after deleting a CNAME record', function () {
    fakePihole();

    $connector = new PiholeConnector(piholeProvider());

    $connector->deleteRecord('alias.example.com,target.example.com');
    Sleep::assertNeverSlept();

    $connector->deleteRecord('192.168.1.10 host.example.com');
    Sleep::assertSleptTimes(1);
});

it('runs a CNAME update in one uninterrupted session and paces only the following one', function () {
    fakePihole();

    $connector = new PiholeConnector(piholeProvider());
    $entry = DnsEntry::factory()->cname('new-target.example.com')->for(piholeZone(), 'zone')->create([
        'name' => 'alias',
        'ttl' => null,
    ]);

    // Delete-old + put-new happen back-to-back — no sleep inside the update.
    $connector->updateRecord($entry, 'alias.example.com,old-target.example.com');
    Sleep::assertNeverSlept();

    $connector->testConnection();
    Sleep::assertSleptTimes(1);
});

it('releases the per-provider session lock even when the operation fails', function () {
    fakePihole([
        'POST /api/auth' => piholeError('unauthorized', 'Unauthorized', 401),
    ]);

    $provider = piholeProvider();
    $connector = new PiholeConnector($provider);

    expect(fn () => $connector->listRecords())->toThrow(ConnectorException::class);

    expect(Cache::lock("pihole-session:{$provider->id}", 5)->get())->toBeTrue();
});

it('operates normally with TLS verification disabled', function () {
    fakePihole();

    $connector = new PiholeConnector(piholeProvider(['verify_tls' => false]));

    // withoutVerifying() is a Guzzle transport option that Http::fake() does not
    // expose, so we only assert the connector works end-to-end with the flag set.
    $result = $connector->testConnection();

    expect($result->ok)->toBeTrue();
});
