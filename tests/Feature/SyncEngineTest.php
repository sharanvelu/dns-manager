<?php

use App\Enums\SyncStatus;
use App\Jobs\CheckProviderDrift;
use App\Jobs\DeleteEntryFromProvider;
use App\Jobs\SyncEntryToProvider;
use App\Models\DnsEntry;
use App\Models\DnsZone;
use App\Models\Provider;
use App\Models\ZoneProvider;
use App\Services\SyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function attachCloudflare(DnsZone $zone, array $providerAttributes = [], array $attachmentAttributes = []): ZoneProvider
{
    $provider = Provider::factory()->cloudflare()->create($providerAttributes + [
        'managed_record_types' => ['A', 'AAAA', 'CNAME', 'MX'],
    ]);

    return ZoneProvider::factory()->create($attachmentAttributes + [
        'dns_zone_id' => $zone->id,
        'provider_id' => $provider->id,
        'config' => ['zone_id' => 'cf-zone-'.$zone->id],
    ]);
}

function attachPihole(DnsZone $zone, ?Provider $provider = null, array $attachmentAttributes = []): ZoneProvider
{
    $provider ??= Provider::factory()->pihole()->create();

    return ZoneProvider::factory()->create($attachmentAttributes + [
        'dns_zone_id' => $zone->id,
        'provider_id' => $provider->id,
    ]);
}

function cfApiRecord(string $id, string $type, string $name, string $content, array $extra = []): array
{
    return $extra + [
        'id' => $id, 'type' => $type, 'name' => $name, 'content' => $content,
        'ttl' => 1, 'proxied' => false,
    ];
}

function cfListResponse(array $records): array
{
    return [
        'success' => true, 'errors' => [], 'messages' => [],
        'result' => $records,
        'result_info' => ['page' => 1, 'total_pages' => 1],
    ];
}

function syncEngine(): SyncService
{
    return app(SyncService::class);
}

test('a new entry targets every compatible attachment of its zone and no other', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $otherZone = DnsZone::factory()->create(['name' => 'other.test']);

    $cloudflare = attachCloudflare($zone);
    $pihole = attachPihole($zone);
    $aOnly = attachPihole($zone, Provider::factory()->pihole()->create(['managed_record_types' => ['A']]));
    $disabledProvider = attachCloudflare($zone, ['enabled' => false]);
    $foreign = attachCloudflare($otherZone);

    $entry = DnsEntry::factory()->cname()->for($zone, 'zone')->create();

    syncEngine()->syncEntry($entry);

    expect($entry->syncStates()->pluck('zone_provider_id')->all())
        ->toEqualCanonicalizing([$cloudflare->id, $pihole->id]);

    Queue::assertPushed(SyncEntryToProvider::class, 2);
    Queue::assertNotPushed(SyncEntryToProvider::class, fn ($job) => $job->zoneProviderId === $aOnly->id);
    Queue::assertNotPushed(SyncEntryToProvider::class, fn ($job) => $job->zoneProviderId === $disabledProvider->id);
    Queue::assertNotPushed(SyncEntryToProvider::class, fn ($job) => $job->zoneProviderId === $foreign->id);
});

test('an explicit attachment selection limits where the entry syncs', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create();
    $cloudflare = attachCloudflare($zone);
    $pihole = attachPihole($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create();

    syncEngine()->syncEntry($entry, [$pihole->id]);

    expect($entry->syncStates()->pluck('zone_provider_id')->all())->toBe([$pihole->id]);
    Queue::assertPushed(SyncEntryToProvider::class, 1);
    Queue::assertNotPushed(SyncEntryToProvider::class, fn ($job) => $job->zoneProviderId === $cloudflare->id);
});

test('an attachment belonging to another zone cannot be targeted explicitly', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create();
    $otherZone = DnsZone::factory()->create();
    attachCloudflare($zone);
    $foreign = attachCloudflare($otherZone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create();

    syncEngine()->syncEntry($entry, [$foreign->id]);

    expect($entry->syncStates()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('manual re-sync keeps the existing attachment assignment', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create();
    $assigned = attachCloudflare($zone);
    $unassigned = attachPihole($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create();
    $entry->syncStates()->create(['zone_provider_id' => $assigned->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-1']);

    syncEngine()->syncEntry($entry);

    Queue::assertPushed(SyncEntryToProvider::class, 1);
    Queue::assertNotPushed(SyncEntryToProvider::class, fn ($job) => $job->zoneProviderId === $unassigned->id);
    expect($entry->syncStates()->count())->toBe(1);
});

test('deselecting an attachment removes the record from it', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $cloudflare = attachCloudflare($zone);
    $pihole = attachPihole($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create(['content' => '10.0.0.9']);
    $entry->syncStates()->create(['zone_provider_id' => $cloudflare->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-1']);
    $entry->syncStates()->create(['zone_provider_id' => $pihole->id, 'sync_status' => SyncStatus::Synced, 'external_id' => "10.0.0.9 {$entry->fqdn}"]);

    syncEngine()->syncEntry($entry, [$pihole->id]);

    expect($entry->syncStates()->where('zone_provider_id', $cloudflare->id)->sole()->sync_status)->toBe(SyncStatus::Deleting);
    Queue::assertPushed(DeleteEntryFromProvider::class, 1);
    Queue::assertPushed(SyncEntryToProvider::class, fn ($job) => $job->zoneProviderId === $pihole->id);
});

test('a deselected state without a remote record is dropped without a delete job', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create();
    $kept = attachCloudflare($zone);
    $dropped = attachCloudflare($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create();
    $entry->syncStates()->create(['zone_provider_id' => $dropped->id, 'sync_status' => SyncStatus::Error, 'external_id' => null]);

    syncEngine()->syncEntry($entry, [$kept->id]);

    Queue::assertNotPushed(DeleteEntryFromProvider::class);
    expect($entry->syncStates()->pluck('zone_provider_id')->all())->toBe([$kept->id]);
});

test('states of inactive attachments are paused, not deleted, on edit', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $active = attachCloudflare($zone);
    $attachmentDisabled = attachCloudflare($zone, [], ['enabled' => false]);
    $providerDisabled = attachPihole($zone, Provider::factory()->pihole()->disabled()->create());

    $entry = DnsEntry::factory()->for($zone, 'zone')->create(['content' => '10.0.0.9']);
    $entry->syncStates()->create(['zone_provider_id' => $active->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-1']);
    $entry->syncStates()->create(['zone_provider_id' => $attachmentDisabled->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-2']);
    $entry->syncStates()->create(['zone_provider_id' => $providerDisabled->id, 'sync_status' => SyncStatus::Synced, 'external_id' => "10.0.0.9 {$entry->fqdn}"]);

    syncEngine()->syncEntry($entry, [$active->id]);

    Queue::assertNotPushed(DeleteEntryFromProvider::class);
    expect($entry->syncStates()->where('zone_provider_id', $attachmentDisabled->id)->sole()->sync_status)->toBe(SyncStatus::Synced)
        ->and($entry->syncStates()->where('zone_provider_id', $providerDisabled->id)->sole()->sync_status)->toBe(SyncStatus::Synced);
});

test('a type change reassigns attachments and deletes stale remote records', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $pihole = attachPihole($zone);
    $cloudflare = attachCloudflare($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create(['content' => '10.1.1.1']);
    $entry->syncStates()->create(['zone_provider_id' => $pihole->id, 'sync_status' => SyncStatus::Synced, 'external_id' => "10.1.1.1 {$entry->fqdn}"]);
    $entry->syncStates()->create(['zone_provider_id' => $cloudflare->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-1']);

    $entry->update(['type' => 'MX', 'content' => 'mail.example.com', 'priority' => 10]);

    syncEngine()->syncEntry($entry);

    // Pi-hole no longer applies (MX unsupported): remote delete queued.
    Queue::assertPushed(DeleteEntryFromProvider::class, 1);
    Queue::assertPushed(SyncEntryToProvider::class, fn ($job) => $job->zoneProviderId === $cloudflare->id);

    expect($entry->syncStates()->where('zone_provider_id', $pihole->id)->sole()->sync_status)
        ->toBe(SyncStatus::Deleting);
});

test('sync job pushes to cloudflare through the zone attachment and stores the external id', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [],
            'result' => ['id' => 'cf-record-1'],
        ]),
    ]);

    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $attachment = attachCloudflare($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create(['name' => 'app']);
    $entry->syncStates()->create(['zone_provider_id' => $attachment->id, 'sync_status' => SyncStatus::Pending]);

    (new SyncEntryToProvider($entry->id, $attachment->id))->handle();

    $state = $entry->syncStates()->sole();
    expect($state->external_id)->toBe('cf-record-1')
        ->and($state->sync_status)->toBe(SyncStatus::Synced)
        ->and($state->last_synced_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), "/zones/cf-zone-{$zone->id}/dns_records"));

    $this->assertDatabaseHas('sync_logs', [
        'provider_id' => $attachment->provider_id,
        'dns_zone_id' => $zone->id,
        'dns_entry_id' => $entry->id,
        'action' => 'push',
        'status' => 'success',
    ]);
});

test('sync job bails without touching the network when the attachment is inactive', function () {
    Http::fake();

    $zone = DnsZone::factory()->create();
    $attachment = attachCloudflare($zone, [], ['enabled' => false]);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create();
    $entry->syncStates()->create(['zone_provider_id' => $attachment->id, 'sync_status' => SyncStatus::Pending]);

    (new SyncEntryToProvider($entry->id, $attachment->id))->handle();

    Http::assertNothingSent();
    expect($entry->syncStates()->sole()->sync_status)->toBe(SyncStatus::Pending);
});

test('sync recreates a tracked record that was deleted at the provider', function () {
    Http::fake([
        // The tracked record is gone remotely — Cloudflare 404s the update.
        'api.cloudflare.com/client/v4/zones/*/dns_records/cf-gone' => Http::response([
            'success' => false,
            'errors' => [['code' => 81044, 'message' => 'Record does not exist.']],
            'result' => null,
        ], 404),
        'api.cloudflare.com/client/v4/zones/*/dns_records' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [],
            'result' => ['id' => 'cf-recreated'],
        ]),
    ]);

    $zone = DnsZone::factory()->create();
    $attachment = attachCloudflare($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create();
    $entry->syncStates()->create([
        'zone_provider_id' => $attachment->id,
        'sync_status' => SyncStatus::Drifted,
        'external_id' => 'cf-gone',
        'last_error' => 'Record no longer exists at the provider.',
    ]);

    (new SyncEntryToProvider($entry->id, $attachment->id))->handle();

    $state = $entry->syncStates()->sole();
    expect($state->external_id)->toBe('cf-recreated')
        ->and($state->sync_status)->toBe(SyncStatus::Synced)
        ->and($state->last_error)->toBeNull();

    Http::assertSentCount(2);
});

test('failed sync marks the state as error with the message', function () {
    $zone = DnsZone::factory()->create();
    $attachment = attachCloudflare($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create();
    $entry->syncStates()->create(['zone_provider_id' => $attachment->id, 'sync_status' => SyncStatus::Pending]);

    $job = new SyncEntryToProvider($entry->id, $attachment->id);
    $job->failed(new RuntimeException('Cloudflare: create failed with HTTP 400: [81057] Record already exists.'));

    $state = $entry->syncStates()->sole();
    expect($state->sync_status)->toBe(SyncStatus::Error)
        ->and($state->last_error)->toContain('81057');

    $this->assertDatabaseHas('sync_logs', ['action' => 'push', 'status' => 'error', 'dns_entry_id' => $entry->id]);
});

test('deleting an entry removes it remotely then locally', function () {
    Http::fake([
        'api.cloudflare.com/client/v4/zones/*/dns_records/cf-record-1' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [], 'result' => ['id' => 'cf-record-1'],
        ]),
    ]);

    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $attachment = attachCloudflare($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create();
    $state = $entry->syncStates()->create([
        'zone_provider_id' => $attachment->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => 'cf-record-1',
    ]);

    Queue::fake();
    syncEngine()->deleteEntry($entry);

    expect($entry->fresh()->syncStates()->sole()->sync_status)->toBe(SyncStatus::Deleting);
    Queue::assertPushed(DeleteEntryFromProvider::class, 1);

    (new DeleteEntryFromProvider($state->id))->handle();

    expect(DnsEntry::find($entry->id))->toBeNull();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE');

    $this->assertDatabaseHas('sync_logs', [
        'action' => 'delete',
        'status' => 'success',
        'dns_zone_id' => $zone->id,
    ]);
});

test('entries without remote state are deleted immediately', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create();
    $attachment = attachCloudflare($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create();
    $entry->syncStates()->create(['zone_provider_id' => $attachment->id, 'sync_status' => SyncStatus::Pending, 'external_id' => null]);

    syncEngine()->deleteEntry($entry);

    expect(DnsEntry::find($entry->id))->toBeNull()
        ->and($attachment->fresh())->not->toBeNull();
    Queue::assertNothingPushed();
});

test('drift check compares each zone attachment against its own zone listing', function () {
    $provider = Provider::factory()->cloudflare()->create();

    $zoneA = DnsZone::factory()->create(['name' => 'alpha.test']);
    $zoneB = DnsZone::factory()->create(['name' => 'beta.test']);

    $attachmentA = ZoneProvider::factory()->create(['dns_zone_id' => $zoneA->id, 'provider_id' => $provider->id, 'config' => ['zone_id' => 'zone-a']]);
    $attachmentB = ZoneProvider::factory()->create(['dns_zone_id' => $zoneB->id, 'provider_id' => $provider->id, 'config' => ['zone_id' => 'zone-b']]);

    $okA = DnsEntry::factory()->for($zoneA, 'zone')->create(['name' => 'ok', 'content' => '10.0.0.1']);
    $changedA = DnsEntry::factory()->for($zoneA, 'zone')->create(['name' => 'changed', 'content' => '10.0.0.2']);
    $missingA = DnsEntry::factory()->for($zoneA, 'zone')->create(['name' => 'gone', 'content' => '10.0.0.3']);
    $okB = DnsEntry::factory()->for($zoneB, 'zone')->create(['name' => 'ok', 'content' => '10.0.0.4']);

    $okA->syncStates()->create(['zone_provider_id' => $attachmentA->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-a1']);
    $changedA->syncStates()->create(['zone_provider_id' => $attachmentA->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-a2']);
    $missingA->syncStates()->create(['zone_provider_id' => $attachmentA->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-a3']);
    $okB->syncStates()->create(['zone_provider_id' => $attachmentB->id, 'sync_status' => SyncStatus::Drifted, 'external_id' => 'cf-b1']);

    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-a/dns_records*' => Http::response(cfListResponse([
            cfApiRecord('cf-a1', 'A', 'ok.alpha.test', '10.0.0.1'),
            cfApiRecord('cf-a2', 'A', 'changed.alpha.test', '192.168.99.99'),
        ])),
        'api.cloudflare.com/client/v4/zones/zone-b/dns_records*' => Http::response(cfListResponse([
            cfApiRecord('cf-b1', 'A', 'ok.beta.test', '10.0.0.4'),
        ])),
    ]);

    (new CheckProviderDrift($provider->id))->handle();

    expect($okA->syncStates()->sole()->sync_status)->toBe(SyncStatus::Synced)
        ->and($okA->syncStates()->sole()->drift_details)->toBeNull()
        ->and($changedA->syncStates()->sole()->sync_status)->toBe(SyncStatus::Drifted)
        ->and($changedA->syncStates()->sole()->last_error)->toBe('Remote record differs from the managed entry.')
        ->and($changedA->syncStates()->sole()->drift_details)->toBe([
            ['field' => 'content', 'tracked' => '10.0.0.2', 'actual' => '192.168.99.99'],
        ])
        ->and($missingA->syncStates()->sole()->sync_status)->toBe(SyncStatus::Drifted)
        ->and($missingA->syncStates()->sole()->last_error)->toBe('Record no longer exists at the provider.')
        ->and($missingA->syncStates()->sole()->drift_details)->toBeNull()
        ->and($okB->syncStates()->sole()->sync_status)->toBe(SyncStatus::Synced)
        ->and($provider->fresh()->health_status->value)->toBe('ok');

    $this->assertDatabaseHas('sync_logs', [
        'provider_id' => $provider->id,
        'action' => 'drift-check',
        'status' => 'success',
        'message' => 'Checked 4 record(s), 2 drifted',
    ]);
});

test('drift check diffs a replaced record via its name when the external id is gone', function () {
    // Tuple external ids (Technitium-style) change with the record's data —
    // the id no longer matches, but the record at the same name+type is the
    // tracked record as it exists now, so the diff is still recorded.
    $zone = DnsZone::factory()->create(['name' => 'alpha.test']);
    $attachment = attachCloudflare($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create(['name' => 'replaced', 'content' => '10.0.0.5']);
    $entry->syncStates()->create(['zone_provider_id' => $attachment->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-old']);

    Http::fake([
        'api.cloudflare.com/client/v4/zones/*/dns_records*' => Http::response(cfListResponse([
            cfApiRecord('cf-new', 'A', 'replaced.alpha.test', '10.9.9.9'),
        ])),
    ]);

    (new CheckProviderDrift($attachment->provider_id))->handle();

    $state = $entry->syncStates()->sole();

    expect($state->sync_status)->toBe(SyncStatus::Drifted)
        ->and($state->last_error)->toBe('Remote record differs from the managed entry.')
        ->and($state->drift_details)->toBe([
            ['field' => 'content', 'tracked' => '10.0.0.5', 'actual' => '10.9.9.9'],
        ]);
});

test('a failing zone attachment does not block drift checks for the others', function () {
    $provider = Provider::factory()->cloudflare()->create();

    $zoneA = DnsZone::factory()->create(['name' => 'alpha.test']);
    $zoneB = DnsZone::factory()->create(['name' => 'beta.test']);

    $attachmentA = ZoneProvider::factory()->create(['dns_zone_id' => $zoneA->id, 'provider_id' => $provider->id, 'config' => ['zone_id' => 'zone-a']]);
    $attachmentB = ZoneProvider::factory()->create(['dns_zone_id' => $zoneB->id, 'provider_id' => $provider->id, 'config' => ['zone_id' => 'zone-b']]);

    $okA = DnsEntry::factory()->for($zoneA, 'zone')->create(['name' => 'ok', 'content' => '10.0.0.1']);
    $okA->syncStates()->create(['zone_provider_id' => $attachmentA->id, 'sync_status' => SyncStatus::Drifted, 'external_id' => 'cf-a1']);

    $untouchedB = DnsEntry::factory()->for($zoneB, 'zone')->create(['name' => 'ok', 'content' => '10.0.0.4']);
    $untouchedB->syncStates()->create(['zone_provider_id' => $attachmentB->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-b1']);

    Http::fake([
        'api.cloudflare.com/client/v4/zones/zone-a/dns_records*' => Http::response(cfListResponse([
            cfApiRecord('cf-a1', 'A', 'ok.alpha.test', '10.0.0.1'),
        ])),
        'api.cloudflare.com/client/v4/zones/zone-b/dns_records*' => Http::response([
            'success' => false,
            'errors' => [['code' => 9109, 'message' => 'Invalid access token']],
            'result' => null,
        ], 401),
    ]);

    (new CheckProviderDrift($provider->id))->handle();

    $provider->refresh();

    expect($okA->syncStates()->sole()->sync_status)->toBe(SyncStatus::Synced)
        ->and($untouchedB->syncStates()->sole()->sync_status)->toBe(SyncStatus::Synced)
        ->and($provider->health_status->value)->toBe('error')
        ->and($provider->health_message)->toContain('beta.test')
        ->and($provider->health_message)->toContain('9109');

    $this->assertDatabaseHas('sync_logs', [
        'provider_id' => $provider->id,
        'dns_zone_id' => $zoneB->id,
        'action' => 'drift-check',
        'status' => 'error',
    ]);

    $this->assertDatabaseHas('sync_logs', [
        'provider_id' => $provider->id,
        'action' => 'drift-check',
        'status' => 'error',
        'message' => 'Checked 1 record(s), 0 drifted, 1 zone(s) failed',
    ]);
});

test('drift check marks provider unhealthy when the API is unreachable', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 9109, 'message' => 'Invalid access token']], 'result' => null], 401)]);

    $zone = DnsZone::factory()->create();
    $attachment = attachCloudflare($zone);

    (new CheckProviderDrift($attachment->provider_id))->handle();

    $provider = $attachment->provider()->sole();

    expect($provider->health_status->value)->toBe('error')
        ->and($provider->health_message)->toContain('9109');
});

test('zoneless drift check matches records across all attached zones in one listing', function () {
    $pihole = Provider::factory()->pihole()->create();

    $zoneA = DnsZone::factory()->create(['name' => 'example.com']);
    $zoneB = DnsZone::factory()->create(['name' => 'internal.lan']);

    $attachmentA = attachPihole($zoneA, $pihole);
    $attachmentB = attachPihole($zoneB, $pihole);

    $app = DnsEntry::factory()->for($zoneA, 'zone')->create(['name' => 'app', 'content' => '10.0.0.1']);
    $nas = DnsEntry::factory()->for($zoneB, 'zone')->create(['name' => 'nas', 'content' => '10.0.0.2']);
    $gone = DnsEntry::factory()->for($zoneB, 'zone')->create(['name' => 'gone', 'content' => '10.0.0.3']);

    $app->syncStates()->create(['zone_provider_id' => $attachmentA->id, 'sync_status' => SyncStatus::Synced, 'external_id' => '10.0.0.1 app.example.com']);
    $nas->syncStates()->create(['zone_provider_id' => $attachmentB->id, 'sync_status' => SyncStatus::Drifted, 'external_id' => '10.0.0.2 nas.internal.lan']);
    $gone->syncStates()->create(['zone_provider_id' => $attachmentB->id, 'sync_status' => SyncStatus::Synced, 'external_id' => '10.0.0.3 gone.internal.lan']);

    Http::fake([
        'pihole.internal/api/auth' => Http::response(['session' => ['valid' => true, 'sid' => 'sid-1']]),
        'pihole.internal/api/config/dns/hosts' => Http::response([
            'config' => ['dns' => ['hosts' => [
                '10.0.0.1 app.example.com',
                '10.0.0.2 nas.internal.lan',
            ]]],
        ]),
        'pihole.internal/api/config/dns/cnameRecords' => Http::response([
            'config' => ['dns' => ['cnameRecords' => []]],
        ]),
    ]);

    (new CheckProviderDrift($pihole->id))->handle();

    expect($app->syncStates()->sole()->sync_status)->toBe(SyncStatus::Synced)
        ->and($nas->syncStates()->sole()->sync_status)->toBe(SyncStatus::Synced)
        ->and($gone->syncStates()->sole()->sync_status)->toBe(SyncStatus::Drifted)
        ->and($pihole->fresh()->health_status->value)->toBe('ok');

    // One instance-wide listing: login, hosts, cnames, logout — not per zone.
    Http::assertSentCount(4);

    $this->assertDatabaseHas('sync_logs', [
        'provider_id' => $pihole->id,
        'action' => 'drift-check',
        'status' => 'success',
        'message' => 'Checked 3 record(s), 1 drifted',
    ]);
});

test('provider config is stored encrypted at rest', function () {
    $provider = Provider::factory()->cloudflare()->create([
        'config' => ['api_token' => 'super-secret-token'],
    ]);

    $raw = DB::table('providers')->where('id', $provider->id)->value('config');

    expect($raw)->not->toContain('super-secret-token')
        ->and($provider->fresh()->config['api_token'])->toBe('super-secret-token');
});

test('deleting an entry never queues deletes against paused attachments', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create();
    $active = attachCloudflare($zone);
    $paused = attachCloudflare($zone, ['enabled' => false]);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create();
    $activeState = $entry->syncStates()->create([
        'zone_provider_id' => $active->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-active',
    ]);
    $entry->syncStates()->create([
        'zone_provider_id' => $paused->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-paused',
    ]);

    syncEngine()->deleteEntry($entry);

    Queue::assertPushed(DeleteEntryFromProvider::class, 1);
    Queue::assertPushed(DeleteEntryFromProvider::class, fn ($job) => $job->syncStateId === $activeState->id);

    // The paused attachment's state is dropped locally; its remote record stays.
    expect($entry->fresh()->syncStates()->pluck('zone_provider_id')->all())->toBe([$active->id]);
});

test('an entry held only by paused attachments is deleted locally without remote calls', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create();
    $paused = attachCloudflare($zone, ['enabled' => false]);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create();
    $entry->syncStates()->create([
        'zone_provider_id' => $paused->id, 'sync_status' => SyncStatus::Synced, 'external_id' => 'cf-orphaned',
    ]);

    syncEngine()->deleteEntry($entry);

    expect(DnsEntry::find($entry->id))->toBeNull();
    Queue::assertNothingPushed();
});

test('manual re-sync of an entry assigned nowhere stays a no-op', function () {
    Queue::fake();

    $zone = DnsZone::factory()->create();
    attachCloudflare($zone);

    $entry = DnsEntry::factory()->for($zone, 'zone')->create()->fresh();

    syncEngine()->syncEntry($entry);

    Queue::assertNothingPushed();
    expect($entry->syncStates()->count())->toBe(0);
});
