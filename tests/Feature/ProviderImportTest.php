<?php

declare(strict_types = 1);

use App\Models\User;
use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\ZoneUser;
use App\Enums\SyncStatus;
use App\Models\ZoneProvider;
use App\Jobs\SyncEntryToProvider;
use Illuminate\Support\Facades\Http;
use App\Jobs\DeleteEntryFromProvider;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();

    $this->zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->provider = Provider::factory()->cloudflare()->create([
        'name' => 'CF Zone',
        'config' => ['api_token' => 'tok'],
        'managed_record_types' => ['A', 'CNAME'],
    ]);

    $this->attachment = ZoneProvider::factory()->create([
        'dns_zone_id' => $this->zone->id,
        'provider_id' => $this->provider->id,
        'config' => ['zone_id' => 'zone-1'],
    ]);
});

function fakeCloudflareList(array $records): void
{
    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-1/dns_records?*' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [],
            'result' => $records,
            'result_info' => ['page' => 1, 'total_pages' => 1],
        ]),
    ]);
}

function cfRecord(string $id, string $type, string $name, string $content, array $extra = []): array
{
    return array_merge([
        'id' => $id, 'type' => $type, 'name' => $name, 'content' => $content,
        'ttl' => 1, 'proxied' => false,
    ], $extra);
}

function importRecordsUrl(): string
{
    return '/zones/' . test()->zone->id . '/providers/' . test()->attachment->id . '/remote-records';
}

function importUrl(): string
{
    return '/zones/' . test()->zone->id . '/providers/' . test()->attachment->id . '/import';
}

function zoneEntry(array $attributes): DnsEntry
{
    return DnsEntry::factory()->create($attributes + ['dns_zone_id' => test()->zone->id]);
}

test('remote records are listed relativized with local statuses and unmanaged types are hidden', function () {
    $existing = zoneEntry(['name' => 'exists', 'type' => 'A', 'content' => '10.0.0.2']);

    $managed = zoneEntry(['name' => 'managed', 'type' => 'A', 'content' => '10.0.0.3']);
    $managed->syncStates()->create([
        'zone_provider_id' => $this->attachment->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => 'cf-3',
    ]);

    fakeCloudflareList([
        cfRecord('cf-1', 'A', 'new.example.com', '10.0.0.1'),
        cfRecord('cf-2', 'A', 'exists.example.com', '10.0.0.2'),
        cfRecord('cf-3', 'A', 'managed.example.com', '10.0.0.3'),
        cfRecord('cf-4', 'TXT', 'example.com', '"spf"'), // TXT not managed by this provider
    ]);

    $response = $this->getJson(importRecordsUrl())->assertOk();

    $records = collect($response->json('records'));

    expect($records)->toHaveCount(3)
        ->and($records->firstWhere('name', 'new')['status'])->toBe('new')
        ->and($records->firstWhere('name', 'exists')['status'])->toBe('exists')
        ->and($records->firstWhere('name', 'managed')['status'])->toBe('managed')
        ->and($response->json('unmanagedTypeCount'))->toBe(1)
        ->and($response->json('outOfZoneCount'))->toBe(0);
});

test('records outside the zone are excluded and counted', function () {
    // Pi-hole is zoneless — its host list spans every zone it serves.
    $pihole = Provider::factory()->pihole()->create(['managed_record_types' => ['A', 'CNAME']]);
    $attachment = ZoneProvider::factory()->create([
        'dns_zone_id' => $this->zone->id,
        'provider_id' => $pihole->id,
    ]);

    Http::fake([
        'pihole.internal/api/auth' => Http::response(['session' => ['valid' => true, 'sid' => 'sid-1', 'csrf' => 'c', 'validity' => 300]]),
        'pihole.internal/api/config/dns/hosts' => Http::response(['config' => ['dns' => ['hosts' => [
            '192.168.1.5 nas.example.com',
            '192.168.1.6 host.other-zone.dev',
        ]]]]),
        'pihole.internal/api/config/dns/cnameRecords' => Http::response(['config' => ['dns' => ['cnameRecords' => [
            'media.example.com,nas.example.com',
            'alias.other-zone.dev,host.other-zone.dev',
        ]]]]),
    ]);

    $response = $this->getJson("/zones/{$this->zone->id}/providers/{$attachment->id}/remote-records")->assertOk();

    expect(collect($response->json('records'))->pluck('name')->all())->toEqualCanonicalizing(['nas', 'media'])
        ->and($response->json('outOfZoneCount'))->toBe(2);
});

test('excluded records are returned with their reason alongside status counts', function () {
    $existing = zoneEntry(['name' => 'exists', 'type' => 'A', 'content' => '10.0.0.2']);

    fakeCloudflareList([
        cfRecord('cf-1', 'A', 'new.example.com', '10.0.0.1'),
        cfRecord('cf-2', 'A', 'exists.example.com', '10.0.0.2'),
        cfRecord('cf-3', 'TXT', 'example.com', '"v=spf1 -all"'), // app type, not managed here
        cfRecord('cf-4', 'MX', 'www.example.com', 'mail.example.com', ['priority' => 10]), // MX, not managed here
        cfRecord('cf-6', 'HTTPS', 'example.com', '1 . alpn=h2'), // dropped by the connector itself
        cfRecord('cf-5', 'TXT', 'stray.other.dev', '"x"'), // outside the zone wins over the type
    ]);

    $response = $this->getJson(importRecordsUrl())->assertOk();

    expect($response->json('counts'))->toBe(['new' => 1, 'exists' => 1, 'managed' => 0])
        ->and(collect($response->json('records'))->firstWhere('name', 'exists')['entryId'])->toBe($existing->id)
        ->and(collect($response->json('records'))->firstWhere('name', 'new')['entryId'])->toBeNull()
        ->and($response->json('excludedTotal'))->toBe(3)
        ->and($response->json('unmanagedTypeCount'))->toBe(2)
        ->and($response->json('outOfZoneCount'))->toBe(1)
        ->and(collect($response->json('excluded'))->map(fn ($row) => [$row['type'], $row['name'], $row['reason']])->all())
        ->toEqualCanonicalizing([
            ['TXT', '@', 'unmanaged-type'],
            ['MX', 'www', 'unmanaged-type'],
            ['TXT', 'stray.other.dev', 'out-of-zone'],
        ]);
});

test('the excluded list is capped while its total stays exact', function () {
    $limit = App\Http\Controllers\ProviderImportController::EXCLUDED_LIMIT;

    fakeCloudflareList(collect(range(1, $limit + 5))
        ->map(fn (int $i) => cfRecord("cf-{$i}", 'TXT', "t{$i}.example.com", '"x"'))
        ->push(cfRecord('cf-a', 'A', 'kept.example.com', '10.0.0.9'))
        ->all());

    $response = $this->getJson(importRecordsUrl())->assertOk();

    expect($response->json('excluded'))->toHaveCount($limit)
        ->and($response->json('excludedTotal'))->toBe($limit + 5)
        ->and($response->json('unmanagedTypeCount'))->toBe($limit + 5)
        ->and($response->json('records'))->toHaveCount(1);
});

test('listing remote records requires record management, not just zone visibility', function () {
    $viewer = User::factory()->noRoles()->create();
    ZoneUser::factory()->viewer()->create(['user_id' => $viewer->id, 'dns_zone_id' => $this->zone->id]);

    $this->actingAs($viewer);
    $this->getJson(importRecordsUrl())->assertForbidden();

    $this->actingAs(User::factory()->superViewer()->create());
    $this->getJson(importRecordsUrl())->assertForbidden();
});

test('a connector failure surfaces as a 502 with the message', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 9109, 'message' => 'Invalid access token']], 'result' => null], 401)]);

    $this->getJson(importRecordsUrl())
        ->assertStatus(502)
        ->assertJsonPath('message', fn ($message) => str_contains($message, '9109'));
});

test('the import routes are scoped to the attachment\'s zone', function () {
    $otherZone = DnsZone::factory()->create(['name' => 'other.dev']);

    $this->getJson("/zones/{$otherZone->id}/providers/{$this->attachment->id}/remote-records")->assertNotFound();
    $this->post("/zones/{$otherZone->id}/providers/{$this->attachment->id}/import", ['records' => []])->assertNotFound();
});

test('importing inserts new entries and links them to the source attachment only', function () {
    // A second attachment on the zone must not gain the record.
    ZoneProvider::factory()->create([
        'dns_zone_id' => $this->zone->id,
        'provider_id' => Provider::factory()->pihole()->create(['managed_record_types' => ['A', 'CNAME']])->id,
    ]);

    $this->post(importUrl(), [
        'records' => [
            ['externalId' => 'cf-1', 'type' => 'A', 'name' => 'new', 'content' => '10.0.0.1', 'ttl' => 300, 'priority' => null, 'proxied' => true],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $entry = DnsEntry::sole();
    expect($entry->name)->toBe('new')
        ->and($entry->dns_zone_id)->toBe($this->zone->id)
        ->and($entry->ttl)->toBe(300)
        ->and($entry->proxied)->toBeTrue()
        ->and($entry->syncStates()->count())->toBe(1);

    $state = $entry->syncStates()->sole();
    expect($state->zone_provider_id)->toBe($this->attachment->id)
        ->and($state->external_id)->toBe('cf-1')
        ->and($state->sync_status)->toBe(SyncStatus::Synced);

    // No push jobs: the record already exists at the source provider,
    // and it must not spread to other providers.
    Queue::assertNotPushed(SyncEntryToProvider::class);
});

test('importing an existing entry updates it and links without duplicating', function () {
    $entry = zoneEntry(['name' => 'exists', 'type' => 'A', 'content' => '10.0.0.2', 'ttl' => null, 'proxied' => false]);

    // A pasted FQDN with trailing dot relativizes to the stored name.
    $this->post(importUrl(), [
        'records' => [
            ['externalId' => 'cf-2', 'type' => 'A', 'name' => 'EXISTS.example.com.', 'content' => '10.0.0.2', 'ttl' => 3600, 'priority' => null, 'proxied' => true],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(DnsEntry::count())->toBe(1)
        ->and($entry->refresh()->ttl)->toBe(3600)
        ->and($entry->proxied)->toBeTrue()
        ->and($entry->syncStates()->sole()->external_id)->toBe('cf-2');
});

test('re-importing an already-managed record refreshes the pivot instead of duplicating', function () {
    $entry = zoneEntry(['name' => 'managed', 'type' => 'A', 'content' => '10.0.0.3']);
    $entry->syncStates()->create([
        'zone_provider_id' => $this->attachment->id,
        'sync_status' => SyncStatus::Drifted,
        'external_id' => 'old-id',
        'last_error' => 'drift',
    ]);

    $this->post(importUrl(), [
        'records' => [
            ['externalId' => 'cf-3', 'type' => 'A', 'name' => 'managed', 'content' => '10.0.0.3', 'ttl' => null, 'priority' => null, 'proxied' => false],
        ],
    ])->assertRedirect();

    $state = $entry->syncStates()->sole();
    expect($state->external_id)->toBe('cf-3')
        ->and($state->sync_status)->toBe(SyncStatus::Synced)
        ->and($state->last_error)->toBeNull();
});

test('invalid rows are skipped and reported in the flash message', function () {
    $this->post(importUrl(), [
        'records' => [
            ['externalId' => 'cf-1', 'type' => 'A', 'name' => 'good', 'content' => '10.0.0.1', 'ttl' => null, 'priority' => null, 'proxied' => false],
            ['externalId' => 'cf-9', 'type' => 'A', 'name' => 'bad', 'content' => 'not-an-ip', 'ttl' => null, 'priority' => null, 'proxied' => false],
        ],
    ])->assertRedirect();

    expect(DnsEntry::count())->toBe(1)
        ->and(session('success'))->toContain('1 skipped as invalid');
});

test('import requires record management on the zone', function () {
    // An attachment-managing grant is not enough, even on the right zone.
    $providerManager = User::factory()->noRoles()->create();
    ZoneUser::factory()->providerManager()->create(['user_id' => $providerManager->id, 'dns_zone_id' => $this->zone->id]);

    $this->actingAs($providerManager);

    $this->getJson(importRecordsUrl())->assertForbidden();
    $this->post(importUrl(), ['records' => []])->assertForbidden();

    // A zone dns manager passes the gate — the empty payload then fails
    // validation, not authorization.
    $dnsManager = User::factory()->noRoles()->create();
    ZoneUser::factory()->dnsManager()->create(['user_id' => $dnsManager->id, 'dns_zone_id' => $this->zone->id]);

    $this->actingAs($dnsManager);

    $this->post(importUrl(), ['records' => []])->assertSessionHasErrors('records');
});

function trackedEntry(string $name, string $externalId, ?ZoneProvider $attachment = null): DnsEntry
{
    $entry = zoneEntry(['name' => $name, 'type' => 'A', 'content' => '10.0.0.' . random_int(10, 250)]);
    $entry->syncStates()->create([
        'zone_provider_id' => ($attachment ?? test()->attachment)->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => $externalId,
    ]);

    return $entry;
}

test('the listing reports whether the attachment can untrack', function () {
    fakeCloudflareList([]);

    $this->getJson(importRecordsUrl())->assertOk()->assertJsonPath('attachmentActive', true);

    $this->attachment->update(['enabled' => false]);

    $this->getJson(importRecordsUrl())->assertOk()->assertJsonPath('attachmentActive', false);
});

test('untracking keeps the record at the provider and the entry in the app', function () {
    // A second provider keeps its assignment.
    $other = ZoneProvider::factory()->create([
        'dns_zone_id' => $this->zone->id,
        'provider_id' => Provider::factory()->pihole()->create(['name' => 'Pi', 'managed_record_types' => ['A']])->id,
    ]);
    $shared = trackedEntry('shared', 'cf-1');
    $shared->syncStates()->create(['zone_provider_id' => $other->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'pi-1']);
    $only = trackedEntry('only', 'cf-2');

    $this->post(importUrl(), ['untrack' => [$shared->id, $only->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    Queue::assertNotPushed(DeleteEntryFromProvider::class);
    Queue::assertNotPushed(SyncEntryToProvider::class);

    expect(DnsEntry::count())->toBe(2)
        ->and($shared->syncStates()->pluck('zone_provider_id')->all())->toBe([$other->id])
        // Its only provider released: the entry stays, local-only.
        ->and($only->fresh())->not->toBeNull()
        ->and($only->syncStates()->count())->toBe(0)
        ->and(session('success'))->toContain('Stopped tracking 2 entries from CF Zone')
        ->and(session('success'))->toContain('stay at the provider');

    $activity = Activity::query()->where('event', 'providers-changed')->where('subject_id', $shared->id)->sole();
    expect($activity->properties->get('providers'))->toBe(['Pi'])
        ->and($activity->properties->get('kept_at_providers'))->toBe(['CF Zone']);
});

test('untrack drops ids outside the zone, not linked here, or already being deleted', function () {
    $otherZone = DnsZone::factory()->create(['name' => 'other.dev']);
    $foreign = DnsEntry::factory()->create(['dns_zone_id' => $otherZone->id]);
    $unlinked = zoneEntry(['name' => 'unlinked', 'type' => 'A', 'content' => '10.0.0.5']);
    $deleting = trackedEntry('deleting', 'cf-7');
    $deleting->syncStates()->update(['sync_status' => SyncStatus::Deleting]);
    $tracked = trackedEntry('tracked', 'cf-8');

    $this->post(importUrl(), ['untrack' => [$foreign->id, $unlinked->id, $deleting->id, $tracked->id, 999999]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($tracked->syncStates()->count())->toBe(0)
        ->and($deleting->syncStates()->sole()->sync_status)->toBe(SyncStatus::Deleting)
        ->and(session('success'))->toContain('Stopped tracking 1 entry')
        ->and(session('success'))->toContain('4 no longer tracked here');
});

test('untracking is refused while the attachment is paused', function () {
    $tracked = trackedEntry('tracked', 'cf-1');
    $this->attachment->update(['enabled' => false]);

    $this->post(importUrl(), ['untrack' => [$tracked->id]])->assertSessionHasErrors('untrack');

    expect($tracked->syncStates()->count())->toBe(1);

    // A disabled provider pauses the attachment too.
    $this->attachment->update(['enabled' => true]);
    $this->provider->update(['enabled' => false]);

    $this->post(importUrl(), ['untrack' => [$tracked->id]])->assertSessionHasErrors('untrack');

    expect($tracked->syncStates()->count())->toBe(1);
});

test('one submit can import and untrack together', function () {
    $tracked = trackedEntry('old', 'cf-1');

    $this->post(importUrl(), [
        'records' => [
            ['externalId' => 'cf-2', 'type' => 'A', 'name' => 'new', 'content' => '10.0.0.1', 'ttl' => null, 'priority' => null, 'proxied' => false],
        ],
        'untrack' => [$tracked->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(DnsEntry::where('name', 'new')->sole()->syncStates()->sole()->zone_provider_id)->toBe($this->attachment->id)
        ->and($tracked->syncStates()->count())->toBe(0)
        ->and(session('success'))->toContain('Imported 1 new')
        ->and(session('success'))->toContain('stopped tracking 1 entry');

    Queue::assertNotPushed(DeleteEntryFromProvider::class);
});

test('untracking requires record management on the zone', function () {
    $tracked = trackedEntry('tracked', 'cf-1');

    foreach ([User::factory()->superViewer()->create(), tap(User::factory()->noRoles()->create(), fn ($user) => ZoneUser::factory()->providerManager()->create(['user_id' => $user->id, 'dns_zone_id' => $this->zone->id]))] as $user) {
        $this->actingAs($user)->post(importUrl(), ['untrack' => [$tracked->id]])->assertForbidden();
    }

    expect($tracked->syncStates()->count())->toBe(1);
});

test('an empty submit is rejected', function () {
    $this->post(importUrl(), ['records' => [], 'untrack' => []])->assertSessionHasErrors('records');
});
