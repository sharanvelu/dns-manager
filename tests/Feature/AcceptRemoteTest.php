<?php

declare(strict_types = 1);

use App\Models\User;
use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\ZoneUser;
use App\Enums\SyncStatus;
use App\Models\ZoneProvider;
use App\Models\EntrySyncState;
use App\Jobs\SyncEntryToProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();

    $this->zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->cloudflare = ZoneProvider::factory()->create([
        'dns_zone_id' => $this->zone->id,
        'provider_id' => Provider::factory()->cloudflare()->create([
            'name' => 'Cloudflare',
            'managed_record_types' => ['A', 'AAAA', 'CNAME', 'MX', 'TXT'],
        ])->id,
        'config' => ['zone_id' => 'cf-zone-1'],
    ]);

    $this->pihole = ZoneProvider::factory()->create([
        'dns_zone_id' => $this->zone->id,
        'provider_id' => Provider::factory()->pihole()->create([
            'name' => 'Pi-hole',
            'managed_record_types' => ['A', 'CNAME'],
        ])->id,
    ]);
});

function acceptCfRecord(string $id, string $type, string $name, string $content, array $extra = []): array
{
    return $extra + [
        'id' => $id, 'type' => $type, 'name' => $name, 'content' => $content,
        'ttl' => 1, 'proxied' => false,
    ];
}

function fakeAcceptCloudflare(array $records): void
{
    Http::fake([
        'api.cloudflare.com/client/v4/zones/cf-zone-1/dns_records*' => Http::response([
            'success' => true, 'errors' => [], 'messages' => [],
            'result' => $records,
            'result_info' => ['page' => 1, 'total_pages' => 1],
        ]),
    ]);
}

/** An entry assigned to the given attachments, each state => [status, external id]. */
function acceptEntry(DnsZone $zone, array $states, array $attributes = []): DnsEntry
{
    $entry = DnsEntry::factory()->for($zone, 'zone')->create($attributes + ['name' => 'app', 'type' => 'A', 'content' => '10.0.0.1']);

    foreach ($states as $zoneProviderId => [$status, $externalId]) {
        $entry->syncStates()->create([
            'zone_provider_id' => $zoneProviderId,
            'sync_status' => $status,
            'external_id' => $externalId,
            'last_error' => $status === SyncStatus::Drifted ? 'Remote record differs from the managed entry.' : null,
            'drift_details' => $status === SyncStatus::Drifted ? [['field' => 'content', 'tracked' => '10.0.0.1', 'actual' => '10.9.9.9']] : null,
        ]);
    }

    return $entry;
}

function stateOf(DnsEntry $entry, ZoneProvider $attachment): EntrySyncState
{
    return $entry->syncStates()->where('zone_provider_id', $attachment->id)->sole();
}

test('accepting updates the entry from the live remote record, marks it synced and re-pushes only the other providers', function () {
    $entry = acceptEntry($this->zone, [
        $this->cloudflare->id => [SyncStatus::Drifted, 'cf-1'],
        $this->pihole->id => [SyncStatus::Synced, '10.0.0.1 app.example.com'],
    ]);

    // The stored drift details are stale (10.9.9.9) — the live value wins.
    fakeAcceptCloudflare([
        acceptCfRecord('cf-1', 'A', 'app.example.com', '10.8.8.8', ['ttl' => 300, 'proxied' => true]),
    ]);

    $this->post(route('entries.accept-remote', $entry))
        ->assertRedirect()
        ->assertSessionHas('success', 'Accepted Cloudflare values for app.example.com (content, TTL, proxied) — re-syncing 1 other provider.');

    $entry->refresh();

    expect($entry->content)->toBe('10.8.8.8')
        ->and($entry->ttl)->toBe(300)
        ->and($entry->proxied)->toBeTrue();

    $source = stateOf($entry, $this->cloudflare);

    expect($source->sync_status)->toBe(SyncStatus::Synced)
        ->and($source->external_id)->toBe('cf-1')
        ->and($source->drift_details)->toBeNull()
        ->and($source->last_error)->toBeNull()
        ->and($source->last_synced_at)->not->toBeNull()
        ->and(stateOf($entry, $this->pihole)->sync_status)->toBe(SyncStatus::Pending);

    Queue::assertPushed(SyncEntryToProvider::class, 1);
    Queue::assertPushed(SyncEntryToProvider::class, fn (SyncEntryToProvider $job) => $job->entryId === $entry->id && $job->zoneProviderId === $this->pihole->id);

    $this->assertDatabaseHas('sync_logs', ['action' => 'accept-remote', 'status' => 'success', 'dns_entry_id' => $entry->id]);
});

test('accepting records one accepted-remote activity with the provider and changed fields', function () {
    $entry = acceptEntry($this->zone, [$this->cloudflare->id => [SyncStatus::Drifted, 'cf-1']]);

    fakeAcceptCloudflare([acceptCfRecord('cf-1', 'A', 'app.example.com', '10.8.8.8')]);

    $this->post(route('entries.accept-remote', $entry))->assertSessionHas('success');

    $activity = Activity::query()->where('log_name', 'entries')->where('event', 'accepted-remote')->sole();

    expect($activity->subject_id)->toBe($entry->id)
        ->and($activity->causer_id)->toBe(auth()->id())
        ->and($activity->properties['provider'])->toBe('Cloudflare')
        ->and($activity->properties['fields'])->toBe(['content'])
        ->and($activity->properties['dns_zone_id'])->toBe($this->zone->id)
        ->and($activity->attribute_changes['attributes'])->toBe(['content' => '10.8.8.8'])
        ->and($activity->attribute_changes['old'])->toBe(['content' => '10.0.0.1'])
        // No duplicate generic `updated` record for the same change.
        ->and(Activity::query()->where('subject_id', $entry->id)->where('event', 'updated')->exists())->toBeFalse();
});

test('an entry drifted on several providers requires choosing which values to accept', function () {
    $entry = acceptEntry($this->zone, [
        $this->cloudflare->id => [SyncStatus::Drifted, 'cf-1'],
        $this->pihole->id => [SyncStatus::Drifted, '10.0.0.1 app.example.com'],
    ]);

    Http::fake();

    $this->post(route('entries.accept-remote', $entry))
        ->assertSessionHas('error', 'app.example.com drifted on 2 providers — choose which provider\'s values to accept.');

    expect($entry->fresh()->content)->toBe('10.0.0.1');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('choosing a provider accepts its values and overwrites the other drifted provider', function () {
    $entry = acceptEntry($this->zone, [
        $this->cloudflare->id => [SyncStatus::Drifted, 'cf-1'],
        $this->pihole->id => [SyncStatus::Drifted, '10.0.0.1 app.example.com'],
    ]);

    fakeAcceptCloudflare([acceptCfRecord('cf-1', 'A', 'app.example.com', '10.8.8.8')]);

    $this->post(route('entries.accept-remote', $entry), ['zone_provider_id' => $this->cloudflare->id])
        ->assertSessionHas('success');

    expect($entry->fresh()->content)->toBe('10.8.8.8')
        ->and(stateOf($entry, $this->cloudflare)->sync_status)->toBe(SyncStatus::Synced)
        ->and(stateOf($entry, $this->pihole)->sync_status)->toBe(SyncStatus::Pending)
        ->and(stateOf($entry, $this->pihole)->drift_details)->toBeNull();

    Queue::assertPushed(SyncEntryToProvider::class, fn (SyncEntryToProvider $job) => $job->zoneProviderId === $this->pihole->id);
    Queue::assertNotPushed(SyncEntryToProvider::class, fn (SyncEntryToProvider $job) => $job->zoneProviderId === $this->cloudflare->id);
});

test('accepting from a zoneless provider finds the edited record by name and type', function () {
    // Pi-hole ids embed the record data, so the edited record has a new id.
    $entry = acceptEntry($this->zone, [
        $this->pihole->id => [SyncStatus::Drifted, '10.0.0.1 app.example.com'],
        $this->cloudflare->id => [SyncStatus::Synced, 'cf-1'],
    ]);

    Http::fake([
        'pihole.internal/api/auth' => Http::response(['session' => ['valid' => true, 'sid' => 'sid-1']]),
        'pihole.internal/api/config/dns/hosts' => Http::response(['config' => ['dns' => ['hosts' => ['10.0.0.7 app.example.com']]]]),
        'pihole.internal/api/config/dns/cnameRecords' => Http::response(['config' => ['dns' => ['cnameRecords' => []]]]),
    ]);

    $this->post(route('entries.accept-remote', $entry))
        ->assertSessionHas('success', 'Accepted Pi-hole values for app.example.com (content) — re-syncing 1 other provider.');

    expect($entry->fresh()->content)->toBe('10.0.0.7')
        ->and(stateOf($entry, $this->pihole)->external_id)->toBe('10.0.0.7 app.example.com')
        ->and(stateOf($entry, $this->pihole)->sync_status)->toBe(SyncStatus::Synced);

    Queue::assertPushed(SyncEntryToProvider::class, fn (SyncEntryToProvider $job) => $job->zoneProviderId === $this->cloudflare->id);
});

test('a record that matches again is just marked synced without re-pushing', function () {
    $entry = acceptEntry($this->zone, [
        $this->cloudflare->id => [SyncStatus::Drifted, 'cf-1'],
        $this->pihole->id => [SyncStatus::Synced, '10.0.0.1 app.example.com'],
    ]);

    fakeAcceptCloudflare([acceptCfRecord('cf-1', 'A', 'app.example.com', '10.0.0.1')]);

    $this->post(route('entries.accept-remote', $entry))
        ->assertSessionHas('success', 'app.example.com already matches Cloudflare — marked as synced.');

    expect(stateOf($entry, $this->cloudflare)->sync_status)->toBe(SyncStatus::Synced);
    Queue::assertNothingPushed();
});

test('accepting is refused and changes nothing when', function (array $records, string $error, array $extraEntries = []) {
    foreach ($extraEntries as $attributes) {
        DnsEntry::factory()->for($this->zone, 'zone')->create($attributes);
    }

    $entry = acceptEntry($this->zone, [
        $this->cloudflare->id => [SyncStatus::Drifted, 'cf-1'],
        $this->pihole->id => [SyncStatus::Synced, '10.0.0.1 app.example.com'],
    ]);

    fakeAcceptCloudflare($records);

    $this->post(route('entries.accept-remote', $entry))->assertSessionHas('error', $error);

    expect($entry->fresh()->only(['content', 'ttl', 'proxied']))->toBe(['content' => '10.0.0.1', 'ttl' => null, 'proxied' => false])
        ->and(stateOf($entry, $this->cloudflare)->sync_status)->toBe(SyncStatus::Drifted)
        ->and(stateOf($entry, $this->cloudflare)->drift_details)->not->toBeNull()
        ->and(stateOf($entry, $this->pihole)->sync_status)->toBe(SyncStatus::Synced)
        ->and(Activity::query()->where('event', 'accepted-remote')->exists())->toBeFalse();

    Queue::assertNothingPushed();
})->with([
    'the remote record is gone' => [
        [acceptCfRecord('cf-other', 'A', 'other.example.com', '10.0.0.5')],
        'The record for app.example.com no longer exists at Cloudflare — there are no values to accept. Use Sync now to recreate it.',
    ],
    'the remote values are invalid' => [
        [acceptCfRecord('cf-1', 'A', 'app.example.com', '10.0.0.1', ['ttl' => 30])],
        "Cloudflare's values are not valid for app.example.com: The ttl field must be at least 60.",
    ],
    'the remote values duplicate another entry' => [
        [acceptCfRecord('cf-1', 'A', 'app.example.com', '10.0.0.2')],
        "Accepting Cloudflare's values would make app.example.com a duplicate of another entry in example.com.",
        [['name' => 'app', 'type' => 'A', 'content' => '10.0.0.2']],
    ],
    'the remote record was renamed' => [
        [acceptCfRecord('cf-1', 'A', 'renamed.example.com', '10.0.0.8')],
        'The record for app.example.com was renamed or changed type at Cloudflare — edit the entry instead of accepting its values.',
    ],
]);

test('accepting is refused when the provider cannot be reached', function () {
    $entry = acceptEntry($this->zone, [$this->cloudflare->id => [SyncStatus::Drifted, 'cf-1']]);

    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]], 403)]);

    $this->post(route('entries.accept-remote', $entry))->assertSessionHas('error');

    expect(session('error'))->toStartWith('Could not read the current records from Cloudflare:')
        ->and($entry->fresh()->content)->toBe('10.0.0.1')
        ->and(stateOf($entry, $this->cloudflare)->sync_status)->toBe(SyncStatus::Drifted);
});

test('accepting from a paused attachment is refused without contacting it', function (string $paused) {
    $paused === 'attachment'
        ? $this->cloudflare->update(['enabled' => false])
        : $this->cloudflare->provider->update(['enabled' => false]);

    $entry = acceptEntry($this->zone, [$this->cloudflare->id => [SyncStatus::Drifted, 'cf-1']]);

    Http::fake();

    $this->post(route('entries.accept-remote', $entry))
        ->assertSessionHas('error', 'Cloudflare is paused for example.com — enable it before accepting its values.');

    expect(stateOf($entry, $this->cloudflare)->sync_status)->toBe(SyncStatus::Drifted);
    Http::assertNothingSent();
})->with(['attachment', 'provider']);

test('paused other providers are not re-pushed after accepting', function () {
    $this->pihole->update(['enabled' => false]);

    $entry = acceptEntry($this->zone, [
        $this->cloudflare->id => [SyncStatus::Drifted, 'cf-1'],
        $this->pihole->id => [SyncStatus::Synced, '10.0.0.1 app.example.com'],
    ]);

    fakeAcceptCloudflare([acceptCfRecord('cf-1', 'A', 'app.example.com', '10.8.8.8')]);

    $this->post(route('entries.accept-remote', $entry))
        ->assertSessionHas('success', 'Accepted Cloudflare values for app.example.com (content).');

    expect(stateOf($entry, $this->pihole)->sync_status)->toBe(SyncStatus::Synced);
    Queue::assertNothingPushed();
});

test('accepting an entry that is not drifted is refused', function () {
    $entry = acceptEntry($this->zone, [$this->cloudflare->id => [SyncStatus::Synced, 'cf-1']]);

    Http::fake();

    $this->post(route('entries.accept-remote', $entry))
        ->assertSessionHas('error', 'app.example.com is not drifted — there is nothing to accept.');

    $this->post(route('entries.accept-remote', $entry), ['zone_provider_id' => $this->cloudflare->id])
        ->assertSessionHas('error', 'app.example.com is not drifted on Cloudflare — there is nothing to accept.');

    $this->post(route('entries.accept-remote', $entry), ['zone_provider_id' => $this->pihole->id])
        ->assertSessionHas('error', 'app.example.com is not assigned to that provider.');

    Http::assertNothingSent();
});

test('only users who manage records in the entry zone may accept remote values', function (string $persona, bool $allowed) {
    $entry = acceptEntry($this->zone, [$this->cloudflare->id => [SyncStatus::Drifted, 'cf-1']]);

    $user = match ($persona) {
        'super-viewer' => User::factory()->superViewer()->create(),
        'user-admin' => User::factory()->userAdmin()->create(),
        'other-zone-manager' => User::factory()->noRoles()->create(),
        default => User::factory()->noRoles()->create(),
    };

    $grantZone = $persona === 'other-zone-manager' ? DnsZone::factory()->create() : $this->zone;

    match ($persona) {
        'zone-admin' => ZoneUser::factory()->admin()->create(['user_id' => $user->id, 'dns_zone_id' => $grantZone->id]),
        'zone-dns-manager', 'other-zone-manager' => ZoneUser::factory()->dnsManager()->create(['user_id' => $user->id, 'dns_zone_id' => $grantZone->id]),
        'zone-viewer' => ZoneUser::factory()->viewer()->create(['user_id' => $user->id, 'dns_zone_id' => $grantZone->id]),
        'zone-provider-manager' => ZoneUser::factory()->providerManager()->create(['user_id' => $user->id, 'dns_zone_id' => $grantZone->id]),
        default => null,
    };

    fakeAcceptCloudflare([acceptCfRecord('cf-1', 'A', 'app.example.com', '10.8.8.8')]);

    $response = $this->actingAs($user)->post(route('entries.accept-remote', $entry));

    if ($allowed) {
        $response->assertSessionHas('success');
        expect($entry->fresh()->content)->toBe('10.8.8.8');
    } else {
        $response->assertForbidden();
        expect($entry->fresh()->content)->toBe('10.0.0.1');
        Http::assertNothingSent();
    }
})->with([
    'zone admin' => ['zone-admin', true],
    'zone dns manager' => ['zone-dns-manager', true],
    'zone viewer' => ['zone-viewer', false],
    'zone provider manager' => ['zone-provider-manager', false],
    'manager of another zone' => ['other-zone-manager', false],
    'super viewer' => ['super-viewer', false],
    'user admin' => ['user-admin', false],
]);

test('bulk accept adopts each entry\'s single drifted provider, listing each attachment once', function () {
    $first = acceptEntry($this->zone, [
        $this->cloudflare->id => [SyncStatus::Drifted, 'cf-1'],
        $this->pihole->id => [SyncStatus::Synced, '10.0.0.1 app.example.com'],
    ]);
    $second = acceptEntry($this->zone, [$this->cloudflare->id => [SyncStatus::Drifted, 'cf-2']], ['name' => 'web', 'content' => '10.0.0.2']);
    $several = acceptEntry($this->zone, [
        $this->cloudflare->id => [SyncStatus::Drifted, 'cf-3'],
        $this->pihole->id => [SyncStatus::Drifted, '10.0.0.3 multi.example.com'],
    ], ['name' => 'multi', 'content' => '10.0.0.3']);
    $clean = acceptEntry($this->zone, [$this->cloudflare->id => [SyncStatus::Synced, 'cf-4']], ['name' => 'clean', 'content' => '10.0.0.4']);

    fakeAcceptCloudflare([
        acceptCfRecord('cf-1', 'A', 'app.example.com', '10.8.8.1'),
        acceptCfRecord('cf-2', 'A', 'web.example.com', '10.8.8.2', ['ttl' => 600]),
        acceptCfRecord('cf-3', 'A', 'multi.example.com', '10.8.8.3'),
        acceptCfRecord('cf-4', 'A', 'clean.example.com', '10.0.0.4'),
    ]);

    $this->post(route('entries.bulk.accept-remote'), ['ids' => [$first->id, $second->id, $several->id, $clean->id, 99999]])
        ->assertSessionHas('success', 'Accepted remote values for 2 entries — re-syncing their other providers. (1 skipped as not drifted; 1 skipped as drifted on several providers — accept those one at a time.)');

    expect($first->fresh()->content)->toBe('10.8.8.1')
        ->and($second->fresh()->only(['content', 'ttl']))->toBe(['content' => '10.8.8.2', 'ttl' => 600])
        ->and($several->fresh()->content)->toBe('10.0.0.3')
        ->and(stateOf($several, $this->cloudflare)->sync_status)->toBe(SyncStatus::Drifted)
        ->and(stateOf($second, $this->cloudflare)->sync_status)->toBe(SyncStatus::Synced);

    Http::assertSentCount(1);
    Queue::assertPushed(SyncEntryToProvider::class, 1);
    Queue::assertPushed(SyncEntryToProvider::class, fn (SyncEntryToProvider $job) => $job->entryId === $first->id && $job->zoneProviderId === $this->pihole->id);

    expect(Activity::query()->where('event', 'accepted-remote')->count())->toBe(2);
});

test('bulk accept counts refusals and reports them as an error when nothing was accepted', function () {
    $gone = acceptEntry($this->zone, [$this->cloudflare->id => [SyncStatus::Drifted, 'cf-1']]);

    fakeAcceptCloudflare([]);

    $this->post(route('entries.bulk.accept-remote'), ['ids' => [$gone->id]])
        ->assertSessionHas('error', 'No remote values were accepted. (1 refused: The record for app.example.com no longer exists at Cloudflare — there are no values to accept. Use Sync now to recreate it.)');

    expect(stateOf($gone, $this->cloudflare)->sync_status)->toBe(SyncStatus::Drifted);
});

test('bulk accept silently drops entries in zones the user cannot manage', function () {
    $otherZone = DnsZone::factory()->create(['name' => 'other.test']);
    $user = User::factory()->noRoles()->create();
    ZoneUser::factory()->dnsManager()->create(['user_id' => $user->id, 'dns_zone_id' => $otherZone->id]);

    $entry = acceptEntry($this->zone, [$this->cloudflare->id => [SyncStatus::Drifted, 'cf-1']]);

    Http::fake();

    $this->actingAs($user)
        ->post(route('entries.bulk.accept-remote'), ['ids' => [$entry->id]])
        ->assertSessionHas('success', 'No remote values were accepted.');

    expect(stateOf($entry, $this->cloudflare)->sync_status)->toBe(SyncStatus::Drifted);
    Http::assertNothingSent();
});

test('a super viewer cannot bulk accept remote values', function () {
    $entry = acceptEntry($this->zone, [$this->cloudflare->id => [SyncStatus::Drifted, 'cf-1']]);

    Http::fake();

    $this->actingAs(User::factory()->superViewer()->create())
        ->post(route('entries.bulk.accept-remote'), ['ids' => [$entry->id]])
        ->assertSessionHas('success', 'No remote values were accepted.');

    expect(stateOf($entry, $this->cloudflare)->sync_status)->toBe(SyncStatus::Drifted);
    Http::assertNothingSent();
});
