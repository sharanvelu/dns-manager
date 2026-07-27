<?php

declare(strict_types = 1);

use App\Models\User;
use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Enums\SyncStatus;
use App\Models\ZoneProvider;
use App\Jobs\SyncEntryToProvider;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();
});

test('zones index renders with zones, live entry counts, and providers', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com', 'description' => 'Main zone']);
    ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $zone->id]);
    DnsEntry::factory()->count(2)->create(['dns_zone_id' => $zone->id]);

    $this->get('/zones')->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('zones/index')
            ->has('zones', 1)
            ->where('zones.0.name', 'example.com')
            ->where('zones.0.description', 'Main zone')
            ->where('zones.0.entriesCount', 2)
            ->has('zones.0.providers', 1)
            ->where('zones.0.providers.0.type', 'cloudflare')
            ->has('providers', 1)
            ->where('providers.0.supportsZones', true)
    );
});

test('zones index includes status rollups and zoneless provider names', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $attachment = ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $zone->id]);

    $synced = DnsEntry::factory()->create(['dns_zone_id' => $zone->id]);
    $synced->syncStates()->create([
        'zone_provider_id' => $attachment->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => 'cf-1',
    ]);

    $errored = DnsEntry::factory()->create(['dns_zone_id' => $zone->id]);
    $errored->syncStates()->create([
        'zone_provider_id' => $attachment->id,
        'sync_status' => SyncStatus::Error,
        'last_error' => 'boom',
    ]);

    Provider::factory()->pihole()->create(['name' => 'Pi-hole Lab']);
    Provider::factory()->pihole()->disabled()->create(['name' => 'Pi-hole Off']);

    $this->get('/zones')->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('zones/index')
            ->where('zones.0.entriesCount', 2)
            ->where('zones.0.syncedCount', 1)
            ->where('zones.0.driftedCount', 0)
            ->where('zones.0.erroredCount', 1)
            // Disabled zoneless providers never auto-attach — only the
            // enabled one is announced in the create dialog.
            ->where('zonelessProviders', ['Pi-hole Lab'])
    );
});

test('a zone can be created with a normalized name', function () {
    $this->post('/zones', [
        'name' => '  Example.COM.  ',
        'description' => 'Main zone',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $zone = DnsZone::sole();
    expect($zone->name)->toBe('example.com')
        ->and($zone->description)->toBe('Main zone');
});

test('invalid zone names are rejected', function () {
    foreach (['not_a_domain', 'foo', '-bad.com', 'bad-.com', 'spa ce.com'] as $name) {
        $this->post('/zones', ['name' => $name])->assertSessionHasErrors('name');
    }

    expect(DnsZone::count())->toBe(0);
});

test('duplicate zone names are rejected', function () {
    DnsZone::factory()->create(['name' => 'example.com']);

    $this->post('/zones', ['name' => 'Example.com'])->assertSessionHasErrors('name');

    expect(DnsZone::count())->toBe(1);
});

test('a zone can be updated', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->put("/zones/{$zone->id}", [
        'name' => 'example.org',
        'description' => 'Renamed',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $zone->refresh();
    expect($zone->name)->toBe('example.org')
        ->and($zone->description)->toBe('Renamed');
});

test('updating a zone keeps its own name unique-exempt', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->put("/zones/{$zone->id}", [
        'name' => 'example.com',
        'description' => 'Same name, new description',
    ])->assertRedirect()->assertSessionHasNoErrors();
});

test('deleting a zone cascades attachments and flags remote records as kept', function () {
    $zone = DnsZone::factory()->create();
    ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $zone->id]);

    // Always land on the list — the zone's own pages no longer exist.
    $this->delete("/zones/{$zone->id}")->assertRedirect(route('zones.index', absolute: false));

    expect(DnsZone::count())->toBe(0)
        ->and(ZoneProvider::count())->toBe(0)
        ->and(session('success'))->toContain('NOT deleted');
});

test('super viewers can see zones but cannot mutate them', function () {
    $zone = DnsZone::factory()->create();

    $this->actingAs(User::factory()->superViewer()->create());

    $this->get('/zones')->assertOk();
    $this->get("/zones/{$zone->id}")->assertRedirect("/zones/{$zone->id}/records");
    $this->get("/zones/{$zone->id}/records")->assertOk();
    $this->get("/zones/{$zone->id}/providers")->assertOk();
    $this->post('/zones', ['name' => 'example.com'])->assertForbidden();
    $this->put("/zones/{$zone->id}", ['name' => 'example.com'])->assertForbidden();
    $this->delete("/zones/{$zone->id}")->assertForbidden();
    $this->post("/zones/{$zone->id}/sync")->assertForbidden();
    $this->post("/zones/{$zone->id}/sync-drifted")->assertForbidden();
});

test('sync drifted re-queues only the zone\'s drifted states, each to its own attachment', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $otherZone = DnsZone::factory()->create(['name' => 'other.test']);

    $attachment = ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $zone->id]);
    $paused = ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $zone->id, 'enabled' => false]);
    $foreign = ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $otherZone->id]);

    $drifted = DnsEntry::factory()->create(['dns_zone_id' => $zone->id, 'name' => 'drifted']);
    $driftedState = $drifted->syncStates()->create([
        'zone_provider_id' => $attachment->id,
        'sync_status' => SyncStatus::Drifted,
        'external_id' => 'cf-1',
        'last_error' => 'Remote record differs from the managed entry.',
        'drift_details' => [['field' => 'content', 'tracked' => '10.0.0.1', 'actual' => '10.9.9.9']],
    ]);

    $synced = DnsEntry::factory()->create(['dns_zone_id' => $zone->id, 'name' => 'synced']);
    $syncedState = $synced->syncStates()->create([
        'zone_provider_id' => $attachment->id,
        'sync_status' => SyncStatus::Synced,
        'external_id' => 'cf-2',
    ]);

    // Paused attachments keep waiting — nothing is queued against them.
    $pausedDrifted = DnsEntry::factory()->create(['dns_zone_id' => $zone->id, 'name' => 'paused']);
    $pausedState = $pausedDrifted->syncStates()->create([
        'zone_provider_id' => $paused->id,
        'sync_status' => SyncStatus::Drifted,
        'external_id' => 'cf-3',
    ]);

    $foreignDrifted = DnsEntry::factory()->create(['dns_zone_id' => $otherZone->id, 'name' => 'foreign']);
    $foreignState = $foreignDrifted->syncStates()->create([
        'zone_provider_id' => $foreign->id,
        'sync_status' => SyncStatus::Drifted,
        'external_id' => 'cf-4',
    ]);

    $this->post("/zones/{$zone->id}/sync-drifted")->assertRedirect();

    expect(session('success'))->toContain('1 drifted record');

    expect($driftedState->fresh()->sync_status)->toBe(SyncStatus::Pending)
        ->and($driftedState->fresh()->last_error)->toBeNull()
        ->and($driftedState->fresh()->drift_details)->toBeNull()
        ->and($syncedState->fresh()->sync_status)->toBe(SyncStatus::Synced)
        ->and($pausedState->fresh()->sync_status)->toBe(SyncStatus::Drifted)
        ->and($foreignState->fresh()->sync_status)->toBe(SyncStatus::Drifted);

    Queue::assertPushed(SyncEntryToProvider::class, 1);
    Queue::assertPushed(SyncEntryToProvider::class, fn (SyncEntryToProvider $job) => $job->entryId === $drifted->id
        && $job->zoneProviderId === $attachment->id);
});

test('sync drifted with nothing drifted queues nothing', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);

    $this->post("/zones/{$zone->id}/sync-drifted")->assertRedirect();

    expect(session('success'))->toContain('No drifted records');
    Queue::assertNothingPushed();
});

test('zone changes are recorded in the activity log', function () {
    $this->post('/zones', ['name' => 'example.com']);

    $created = Activity::query()->where('log_name', 'zones')->where('event', 'created')->sole();
    expect($created->subject)->toBeInstanceOf(DnsZone::class);

    $zone = DnsZone::sole();
    $this->put("/zones/{$zone->id}", ['name' => 'example.com', 'description' => 'Changed']);

    $updated = Activity::query()->where('log_name', 'zones')->where('event', 'updated')->sole();
    expect(data_get($updated->attribute_changes, 'attributes.description'))->toBe('Changed');
});
