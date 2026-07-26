<?php

use App\Models\DnsEntry;
use App\Models\DnsZone;
use App\Models\Provider;
use App\Models\User;
use App\Models\ZoneProvider;
use App\Models\ZoneUser;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

/** A user whose ONLY access is a single zone grant. */
function zoneScopedUser(DnsZone $zone, string $state = 'dnsManager'): User
{
    $user = User::factory()->noRoles()->create();

    ZoneUser::factory()->{$state}()->create(['user_id' => $user->id, 'dns_zone_id' => $zone->id]);

    return $user;
}

// ── Entries page ────────────────────────────────────────────────────────────

test('the entries page hides other zones from zone-scoped users', function () {
    $granted = DnsZone::factory()->create(['name' => 'granted.dev']);
    $hidden = DnsZone::factory()->create(['name' => 'hidden.dev']);

    $grantedAttachment = ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $granted->id]);
    ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $hidden->id]);

    $mine = DnsEntry::factory()->create(['dns_zone_id' => $granted->id, 'name' => 'mine']);
    DnsEntry::factory()->create(['dns_zone_id' => $hidden->id, 'name' => 'theirs']);

    $this->actingAs(zoneScopedUser($granted));

    $this->get('/entries')->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('entries/index')
            // Entries, zone options, and attachments all shrink to the grant.
            ->has('entries.data', 1)
            ->where('entries.data.0.id', $mine->id)
            ->where('entries.data.0.name', 'mine')
            ->has('zones', 1)
            ->where('zones.0.id', $granted->id)
            ->has("zoneAttachments.{$granted->id}", 1)
            ->where("zoneAttachments.{$granted->id}.0.id", $grantedAttachment->id)
            ->missing("zoneAttachments.{$hidden->id}")
            // Per-zone abilities ride along for the row actions.
            ->where("zoneCan.{$granted->id}.manageRecords", true)
            ->where("zoneCan.{$granted->id}.viewActivity", false)
            ->missing("zoneCan.{$hidden->id}")
    );

    // Filtering by the hidden zone leaks nothing.
    $this->get("/entries?zone={$hidden->id}")->assertOk()->assertInertia(
        fn ($page) => $page->has('entries.data', 0)
    );
});

// ── Zones page ──────────────────────────────────────────────────────────────

test('the zones page lists only granted zones and no create-dialog fodder', function () {
    $granted = DnsZone::factory()->create(['name' => 'granted.dev']);
    DnsZone::factory()->create(['name' => 'hidden.dev']);

    // An enabled zoneless provider exists — its name must not leak.
    Provider::factory()->pihole()->create(['name' => 'Pi-hole Lab']);

    $this->actingAs(zoneScopedUser($granted, 'admin'));

    $this->get('/zones')->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('zones/index')
            ->has('zones', 1)
            ->where('zones.0.id', $granted->id)
            ->where('providers', [])
            ->where('zonelessProviders', [])
    );
});

// ── Zone providers page ─────────────────────────────────────────────────────

test('the zone providers page hides attach-dialog fodder from non-attachment-managers', function () {
    $zone = DnsZone::factory()->create();
    Provider::factory()->pihole()->create(['name' => 'Pi-hole Lab']);

    $this->actingAs(zoneScopedUser($zone, 'viewer'));

    $this->get("/zones/{$zone->id}/providers")->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('zones/providers')
            ->where('availableProviders', [])
            ->where('connectors', [])
            ->where('zoneCan.manageRecords', false)
            ->where('zoneCan.manageAttachments', false)
            ->where('zoneCan.updateZone', false)
            ->where('zoneCan.deleteZone', false)
            ->where('zoneCan.viewActivity', true)
            ->where('zoneCan.viewAccess', false)
            ->where('zoneCan.manageAccess', false)
    );

    $this->actingAs(zoneScopedUser($zone, 'admin'));

    $this->get("/zones/{$zone->id}/providers")->assertOk()->assertInertia(
        fn ($page) => $page
            ->has('availableProviders', 1)
            ->where('availableProviders.0.name', 'Pi-hole Lab')
            ->has('connectors')
            ->where('zoneCan.manageRecords', true)
            ->where('zoneCan.manageAttachments', true)
            ->where('zoneCan.updateZone', true)
            ->where('zoneCan.deleteZone', false)
            ->where('zoneCan.viewActivity', true)
            ->where('zoneCan.viewAccess', true)
            ->where('zoneCan.manageAccess', true)
    );
});

// ── Dashboard ───────────────────────────────────────────────────────────────

test('the dashboard scopes stats to granted zones and hides provider health', function () {
    $granted = DnsZone::factory()->create(['name' => 'granted.dev']);
    $hidden = DnsZone::factory()->create(['name' => 'hidden.dev']);

    // A healthy global provider exists but must not surface.
    Provider::factory()->cloudflare()->create(['health_status' => 'ok']);

    DnsEntry::factory()->count(2)->create(['dns_zone_id' => $granted->id]);
    DnsEntry::factory()->count(3)->create(['dns_zone_id' => $hidden->id]);

    $this->actingAs(zoneScopedUser($granted, 'viewer'));

    $this->get('/dashboard')->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('dashboard')
            ->where('noAccess', false)
            ->where('isUserAdmin', false)
            ->where('stats.totalEntries', 2)
            ->where('stats.providersTotal', 0)
            ->where('stats.providersHealthy', 0)
            ->where('providers', [])
            ->has('zones', 1)
            ->where('zones.0.id', $granted->id)
    );
});

// ── Zone activity page ──────────────────────────────────────────────────────

test('the zone activity page lists only the causers of that zone', function () {
    $adminA = User::factory()->create(['name' => 'Alice Admin']);
    $adminB = User::factory()->create(['name' => 'Bob Admin']);

    $zoneA = DnsZone::factory()->create(['name' => 'a.dev']);
    $zoneB = DnsZone::factory()->create(['name' => 'b.dev']);

    $this->actingAs($adminA)->post('/entries', [
        'dns_zone_id' => $zoneA->id, 'name' => 'app', 'type' => 'A', 'content' => '10.0.0.1', 'proxied' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $this->actingAs($adminB)->post('/entries', [
        'dns_zone_id' => $zoneB->id, 'name' => 'app', 'type' => 'A', 'content' => '10.0.0.2', 'proxied' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    // The causer filter options never enumerate users from other zones.
    $this->actingAs(zoneScopedUser($zoneA, 'viewer'));

    $this->get("/zones/{$zoneA->id}/activity")->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('zones/activity')
            ->has('users', 1)
            ->where('users.0.id', $adminA->id)
            ->where('users.0.name', 'Alice Admin')
    );
});
