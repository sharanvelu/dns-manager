<?php

use App\Enums\SyncStatus;
use App\Models\DnsEntry;
use App\Models\DnsZone;
use App\Models\Provider;
use App\Models\SyncLog;
use App\Models\User;
use App\Models\ZoneProvider;

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('dashboard')
            ->has('stats')
            ->has('providers')
            ->has('zones')
            ->has('activity'),
    );
});

test('the dashboard aggregates entry, provider, and zone stats', function () {
    $this->actingAs(User::factory()->create());

    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $emptyZone = DnsZone::factory()->create(['name' => 'empty.dev']);

    $provider = Provider::factory()->cloudflare()->create(['name' => 'CF', 'health_status' => 'ok']);
    $attachment = ZoneProvider::factory()->create([
        'dns_zone_id' => $zone->id,
        'provider_id' => $provider->id,
        'config' => ['zone_id' => 'cf-zone-1'],
    ]);

    $makeEntry = function (?string $status, string $content) use ($zone, $attachment) {
        $entry = DnsEntry::factory()->create(['dns_zone_id' => $zone->id, 'content' => $content]);

        if ($status !== null) {
            $entry->syncStates()->create([
                'zone_provider_id' => $attachment->id,
                'sync_status' => $status,
                'external_id' => "ext-{$entry->id}",
            ]);
        }

        return $entry;
    };

    $makeEntry(SyncStatus::Synced->value, '10.0.0.1');
    $makeEntry(SyncStatus::Drifted->value, '10.0.0.2');
    $makeEntry(SyncStatus::Error->value, '10.0.0.3');
    $makeEntry(null, '10.0.0.4'); // no states — not in sync

    $this->get('/dashboard')->assertOk()->assertInertia(
        fn ($page) => $page
            ->where('stats.totalEntries', 4)
            ->where('stats.inSync', 1)
            ->where('stats.drifted', 1)
            ->where('stats.errored', 1)
            ->where('stats.providersTotal', 1)
            ->where('stats.providersHealthy', 1)
            ->where('providers.0.name', 'CF')
            ->where('providers.0.recordsCount', 3)
            ->where('providers.0.syncedCount', 1)
            ->where('providers.0.driftedCount', 1)
            ->where('providers.0.errorCount', 1)
            ->has('zones', 2)
            ->where('zones.1.name', 'example.com')
            ->where('zones.1.entriesCount', 4)
            ->where('zones.1.syncedCount', 1)
            ->where('zones.1.driftedCount', 1)
            ->where('zones.1.erroredCount', 1)
            ->where('zones.1.providerTypes', ['cloudflare'])
            ->where('zones.0.name', 'empty.dev')
            ->where('zones.0.entriesCount', 0)
            ->where('zones.0.providerTypes', []),
    );
});

test('the activity feed carries the zone of each sync log', function () {
    $this->actingAs(User::factory()->create());

    $zone = DnsZone::factory()->create(['name' => 'example.com']);
    $provider = Provider::factory()->cloudflare()->create(['name' => 'CF']);
    $entry = DnsEntry::factory()->create(['dns_zone_id' => $zone->id]);

    SyncLog::record($provider, $entry, 'create', 'success', 'pushed');
    SyncLog::record($provider, null, 'import', 'success', 'imported', $zone->id);
    SyncLog::record($provider, null, 'health', 'error', 'unreachable');

    $this->get('/dashboard')->assertOk()->assertInertia(
        fn ($page) => $page
            ->has('activity', 3)
            ->where('activity.0.zone', null)
            ->where('activity.1.zone', ['id' => $zone->id, 'name' => 'example.com'])
            ->where('activity.2.zone', ['id' => $zone->id, 'name' => 'example.com'])
            ->where('activity.2.entry.id', $entry->id),
    );
});
