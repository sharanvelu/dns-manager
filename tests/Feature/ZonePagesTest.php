<?php

use App\Enums\SyncStatus;
use App\Jobs\SyncEntryToProvider;
use App\Models\DnsEntry;
use App\Models\DnsZone;
use App\Models\Provider;
use App\Models\User;
use App\Models\ZoneProvider;
use App\Models\ZoneUser;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Queue::fake();
});

// ── Zone landing ────────────────────────────────────────────────────────────

test('opening a zone redirects to its records tab', function () {
    $zone = DnsZone::factory()->create();

    $this->get("/zones/{$zone->id}")->assertRedirect("/zones/{$zone->id}/records");
});

// ── Providers ───────────────────────────────────────────────────────────────

test('the zone providers page renders attachments, available providers, and recent activity', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com', 'description' => 'Main zone']);

    $provider = Provider::factory()->cloudflare()->create([
        'name' => 'Cloudflare Prod',
        'config' => ['api_token' => 'super-secret-token-value'],
    ]);
    $attachment = ZoneProvider::factory()->create([
        'dns_zone_id' => $zone->id,
        'provider_id' => $provider->id,
        'config' => ['zone_id' => 'cf-zone-1'],
    ]);

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

    // Enabled but unattached — must show up as an attach candidate.
    Provider::factory()->pihole()->create(['name' => 'Pi-hole Lab']);
    // Disabled providers are never offered.
    Provider::factory()->cloudflare()->disabled()->create(['name' => 'Cloudflare Off']);

    $response = $this->get("/zones/{$zone->id}/providers");

    $response->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('zones/providers')
            ->where('zone.name', 'example.com')
            ->where('zone.description', 'Main zone')
            ->has('attachments', 1)
            ->where('attachments.0.providerName', 'Cloudflare Prod')
            ->where('attachments.0.providerType', 'cloudflare')
            ->where('attachments.0.supportsZones', true)
            // The attachment's zone config is public schema fields only …
            ->where('attachments.0.zoneConfig.zone_id', 'cf-zone-1')
            // … and never the raw config blob.
            ->missing('attachments.0.config')
            ->where('attachments.0.recordsCount', 2)
            ->where('attachments.0.syncedCount', 1)
            ->where('attachments.0.errorCount', 1)
            ->has('availableProviders', 1)
            ->where('availableProviders.0.name', 'Pi-hole Lab')
            ->has('connectors')
    );

    // Provider credentials never reach the page payload.
    $response->assertDontSee('super-secret-token-value');
});

// ── Records ─────────────────────────────────────────────────────────────────

test('the zone records page carries the zone stat tiles', function () {
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

    $drifted = DnsEntry::factory()->create(['dns_zone_id' => $zone->id]);
    $drifted->syncStates()->create([
        'zone_provider_id' => $attachment->id,
        'sync_status' => SyncStatus::Drifted,
    ]);

    $this->get("/zones/{$zone->id}/records")->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('zones/records')
            ->where('stats.entriesCount', 3)
            ->where('stats.inSync', 1)
            ->where('stats.drifted', 1)
            ->where('stats.errored', 1)
    );
});

test('the zone records page is scoped to the zone', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $otherZone = DnsZone::factory()->create(['name' => 'other.dev']);

    $attachment = ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $zone->id]);
    ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $otherZone->id]);

    $entry = DnsEntry::factory()->create(['dns_zone_id' => $zone->id, 'name' => 'app']);
    DnsEntry::factory()->create(['dns_zone_id' => $otherZone->id, 'name' => 'elsewhere']);

    $this->get("/zones/{$zone->id}/records")->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('zones/records')
            ->where('zone.name', 'example.com')
            ->has('entries.data', 1)
            ->where('entries.data.0.id', $entry->id)
            ->where('entries.data.0.name', 'app')
            // Zone mode: exactly this zone as the only option, and only its
            // attachments offered as sync targets.
            ->has('zones', 1)
            ->where('zones.0.id', $zone->id)
            ->has("zoneAttachments.{$zone->id}", 1)
            ->where("zoneAttachments.{$zone->id}.0.id", $attachment->id)
            ->missing("zoneAttachments.{$otherZone->id}")
    );
});

test('zone records filters apply within the zone', function () {
    $zone = DnsZone::factory()->create();
    DnsEntry::factory()->create(['dns_zone_id' => $zone->id, 'name' => 'app', 'content' => '10.0.0.1']);
    DnsEntry::factory()->create(['dns_zone_id' => $zone->id, 'name' => 'mail', 'content' => '10.0.0.2']);

    $this->get("/zones/{$zone->id}/records?search=app")->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('zones/records')
            ->has('entries.data', 1)
            ->where('entries.data.0.name', 'app')
            ->where('filters.search', 'app')
    );
});

// ── Activity ────────────────────────────────────────────────────────────────

test('the zone activity page shows zone activities and zone-stamped entry activities only', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $otherZone = DnsZone::factory()->create(['name' => 'other.dev']);

    $entry = DnsEntry::factory()->create(['dns_zone_id' => $zone->id]);
    DnsEntry::factory()->create(['dns_zone_id' => $otherZone->id]);

    // The stamp survives the entry itself being deleted.
    $entry->delete();

    $response = $this->get("/zones/{$zone->id}/activity");

    $response->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('zones/activity')
            ->where('zone.name', 'example.com')
            // Zone created + entry created + entry deleted.
            ->where('activities.meta.total', 3)
            ->has('users')
            ->has('events')
    );

    $items = collect($response->viewData('page')['props']['activities']['data']);

    expect($items->pluck('event')->all())->toEqualCanonicalizing(['created', 'created', 'deleted'])
        ->and($items->where('subjectType', 'zone')->pluck('subjectId')->all())->toEqual([$zone->id]);
});

test('the zone activity page still applies event and causer filters', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $zone->update(['description' => 'Changed']);

    $this->get("/zones/{$zone->id}/activity?event=updated")->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('zones/activity')
            ->where('activities.meta.total', 1)
            ->where('activities.data.0.event', 'updated')
            ->where('filters.event', 'updated')
    );
});

test('the zone activity page sits behind the viewActivity policy', function () {
    $zone = DnsZone::factory()->create();

    // Ungranted user: no view at all.
    $this->actingAs(User::factory()->noRoles()->create())
        ->get("/zones/{$zone->id}/activity")->assertForbidden();

    // zone-dns-manager manages records but never sees the audit trail.
    $dnsManager = User::factory()->noRoles()->create();
    ZoneUser::factory()->dnsManager()->create(['user_id' => $dnsManager->id, 'dns_zone_id' => $zone->id]);
    $this->actingAs($dnsManager)
        ->get("/zones/{$zone->id}/activity")->assertForbidden();

    // zone-viewer and zone-admin do; so do Super Viewer and Super Admin.
    $viewer = User::factory()->noRoles()->create();
    ZoneUser::factory()->create(['user_id' => $viewer->id, 'dns_zone_id' => $zone->id]);
    $this->actingAs($viewer)
        ->get("/zones/{$zone->id}/activity")->assertOk();

    $zoneAdmin = User::factory()->noRoles()->create();
    ZoneUser::factory()->admin()->create(['user_id' => $zoneAdmin->id, 'dns_zone_id' => $zone->id]);
    $this->actingAs($zoneAdmin)
        ->get("/zones/{$zone->id}/activity")->assertOk();

    $this->actingAs(User::factory()->superViewer()->create())
        ->get("/zones/{$zone->id}/activity")->assertOk();

    $this->actingAs(User::factory()->create())
        ->get("/zones/{$zone->id}/activity")->assertOk();
});

// ── Sync all ────────────────────────────────────────────────────────────────

test('sync all queues a sync job for every record in the zone', function () {
    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $attachment = ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $zone->id]);

    DnsEntry::factory()->count(3)->create(['dns_zone_id' => $zone->id])->each(
        fn (DnsEntry $entry) => $entry->syncStates()->create([
            'zone_provider_id' => $attachment->id,
            'sync_status' => SyncStatus::Synced,
        ]),
    );

    // Another zone's entries are untouched; so are entries assigned nowhere.
    DnsEntry::factory()->create();
    DnsEntry::factory()->create(['dns_zone_id' => $zone->id, 'name' => 'unassigned']);

    $this->post("/zones/{$zone->id}/sync")->assertRedirect();

    Queue::assertPushed(SyncEntryToProvider::class, 3);
    expect(session('success'))->toBe('Queued sync for 4 records in example.com.');

    // Every record in the zone got a pending state on the attachment.
    expect($attachment->syncStates()->where('sync_status', SyncStatus::Pending)->count())->toBe(3);
});

test('sync all requires record management on the zone', function () {
    $zone = DnsZone::factory()->create();

    $viewer = User::factory()->noRoles()->create();
    ZoneUser::factory()->create(['user_id' => $viewer->id, 'dns_zone_id' => $zone->id]);

    $this->actingAs($viewer)
        ->post("/zones/{$zone->id}/sync")->assertForbidden();

    $this->actingAs(User::factory()->superViewer()->create())
        ->post("/zones/{$zone->id}/sync")->assertForbidden();

    Queue::assertNothingPushed();
});
