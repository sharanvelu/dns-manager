<?php

declare(strict_types = 1);

use App\Enums\Role;
use App\Models\User;
use App\Enums\ZoneRole;
use App\Models\DnsZone;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\ZoneUser;
use App\Models\ZoneProvider;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    Queue::fake();
});

function entryPayload(DnsZone $zone): array
{
    return [
        'dns_zone_id' => $zone->id,
        'name' => 'app',
        'type' => 'A',
        'content' => '10.0.0.1',
        'proxied' => false,
    ];
}

function providerPayload(): array
{
    return [
        'name' => 'CF',
        'type' => 'cloudflare',
        'enabled' => true,
        'managed_record_types' => ['A'],
        'config' => ['api_token' => 'tok'],
    ];
}

function attachmentPayload(Provider $provider): array
{
    return [
        'provider_id' => $provider->id,
        'config' => ['zone_id' => 'cf-zone-1'],
        'enabled' => true,
    ];
}

/** A user whose ONLY access is a single zone grant (default: zone-viewer). */
function grantedUser(DnsZone $zone, ?string $state = null): User
{
    $user = User::factory()->noRoles()->create();

    $factory = ZoneUser::factory();

    if ($state !== null) {
        $factory = $factory->{$state}();
    }

    $factory->create(['user_id' => $user->id, 'dns_zone_id' => $zone->id]);

    return $user;
}

// ── Super Viewer ────────────────────────────────────────────────────────────

test('super viewers can open every page', function () {
    $zone = DnsZone::factory()->create();

    $this->actingAs(User::factory()->superViewer()->create());

    $this->get('/dashboard')->assertOk();
    $this->get('/entries')->assertOk();
    $this->get('/zones')->assertOk();
    $this->get("/zones/{$zone->id}")->assertRedirect("/zones/{$zone->id}/records");
    $this->get("/zones/{$zone->id}/records")->assertOk();
    $this->get("/zones/{$zone->id}/providers")->assertOk();
    $this->get("/zones/{$zone->id}/activity")->assertOk();
    $this->getJson("/zones/{$zone->id}/activity/data")->assertOk();
    $this->get("/zones/{$zone->id}/access")->assertOk();
    $this->get('/providers')->assertOk();
    $this->get('/activity')->assertOk();
    $this->getJson('/activity/data')->assertOk();

    // Users are view-users — readable, never manageable (see below).
    $this->get('/users')->assertOk();

    // The sample CSV is deliberately open to any authenticated user.
    $this->get('/entries/import/sample')->assertOk();
});

test('super viewers cannot mutate anything', function () {
    $zone = DnsZone::factory()->create();
    $entry = DnsEntry::factory()->create(['dns_zone_id' => $zone->id]);
    $provider = Provider::factory()->cloudflare()->create();
    $attachment = ZoneProvider::factory()->cloudflare()->create(['dns_zone_id' => $zone->id]);
    $target = User::factory()->noRoles()->create();

    $this->actingAs(User::factory()->superViewer()->create());

    // Entries.
    $this->post('/entries', entryPayload($zone))->assertForbidden();
    $this->put("/entries/{$entry->id}", entryPayload($zone))->assertForbidden();
    $this->delete("/entries/{$entry->id}")->assertForbidden();
    $this->post("/entries/{$entry->id}/sync")->assertForbidden();
    $this->post('/entries/import', [
        'file' => UploadedFile::fake()->createWithContent('entries.csv', "name,type,content\napp,A,10.0.0.1\n"),
        'dns_zone_id' => $zone->id,
    ])->assertForbidden();

    // Zones.
    $this->post('/zones', ['name' => 'new.dev'])->assertForbidden();
    $this->put("/zones/{$zone->id}", ['name' => 'renamed.dev'])->assertForbidden();
    $this->delete("/zones/{$zone->id}")->assertForbidden();
    $this->post("/zones/{$zone->id}/sync")->assertForbidden();

    // Provider import + attachments.
    $this->getJson("/zones/{$zone->id}/providers/{$attachment->id}/remote-records")->assertForbidden();
    $this->post("/zones/{$zone->id}/providers/{$attachment->id}/import", [])->assertForbidden();
    $this->post("/zones/{$zone->id}/providers", attachmentPayload($provider))->assertForbidden();

    // Provider credentials.
    $this->post('/providers', providerPayload())->assertForbidden();
    $this->put("/providers/{$provider->id}", providerPayload())->assertForbidden();
    $this->delete("/providers/{$provider->id}")->assertForbidden();
    $this->post('/providers/test', ['type' => 'cloudflare', 'config' => []])->assertForbidden();
    $this->post("/providers/{$provider->id}/check")->assertForbidden();

    // Users.
    $this->put("/users/{$target->id}", ['roles' => [Role::SuperViewer->value]])->assertForbidden();
    $this->delete("/users/{$target->id}")->assertForbidden();

    // Zone access grants.
    $this->put("/zones/{$zone->id}/access/{$target->id}", ['roles' => ['zone-viewer']])->assertForbidden();
    $this->delete("/zones/{$zone->id}/access/{$target->id}")->assertForbidden();
});

// ── User Admin ──────────────────────────────────────────────────────────────

test('user admins manage users and nothing else', function () {
    $zone = DnsZone::factory()->create();
    $target = User::factory()->noRoles()->create();

    $this->actingAs(User::factory()->userAdmin()->create());

    $this->get('/users')->assertOk();
    $this->put("/users/{$target->id}", ['roles' => [Role::SuperViewer->value]])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($target->refresh()->roles)->toBe([Role::SuperViewer->value]);

    // The dashboard renders its no-zone-access state with the users pointer.
    $this->get('/dashboard')->assertOk()->assertInertia(
        fn ($page) => $page->where('noAccess', true)->where('isUserAdmin', true)
    );

    // No zone, provider, entry, or activity access at all.
    $this->get('/zones')->assertOk()->assertInertia(fn ($page) => $page->has('zones', 0));
    $this->get("/zones/{$zone->id}")->assertForbidden();
    $this->get("/zones/{$zone->id}/records")->assertForbidden();
    $this->get("/zones/{$zone->id}/providers")->assertForbidden();
    $this->get('/providers')->assertForbidden();
    $this->get('/activity')->assertForbidden();
    $this->post('/entries', entryPayload($zone))->assertForbidden();
    $this->post('/zones', ['name' => 'new.dev'])->assertForbidden();
});

// ── No roles, no grants ─────────────────────────────────────────────────────

test('a user with no roles and no grants sees empty pages and no zone access', function () {
    $zone = DnsZone::factory()->create();
    DnsEntry::factory()->create(['dns_zone_id' => $zone->id]);

    $this->actingAs(User::factory()->noRoles()->create());

    $this->get('/dashboard')->assertOk()->assertInertia(
        fn ($page) => $page->where('noAccess', true)->where('isUserAdmin', false)
    );
    $this->get('/zones')->assertOk()->assertInertia(fn ($page) => $page->has('zones', 0));
    $this->get('/entries')->assertOk()->assertInertia(
        fn ($page) => $page->has('entries.data', 0)->has('zones', 0)
    );

    $this->get("/zones/{$zone->id}")->assertForbidden();
    $this->get('/providers')->assertForbidden();
    $this->get('/activity')->assertForbidden();
    $this->get('/users')->assertForbidden();
});

// ── Zone-scoped roles ───────────────────────────────────────────────────────

test('zone dns managers manage records in their zone only', function () {
    $zone = DnsZone::factory()->create(['name' => 'granted.dev']);
    $sibling = DnsZone::factory()->create(['name' => 'sibling.dev']);
    $provider = Provider::factory()->cloudflare()->create();

    $this->actingAs(grantedUser($zone, 'dnsManager'));

    $this->post('/entries', entryPayload($zone))->assertRedirect()->assertSessionHasNoErrors();
    expect(DnsEntry::where('dns_zone_id', $zone->id)->count())->toBe(1);

    // A real zone without a grant is an authorization failure.
    $this->post('/entries', entryPayload($sibling))->assertForbidden();

    $this->get("/zones/{$zone->id}")->assertRedirect("/zones/{$zone->id}/records");
    $this->get("/zones/{$zone->id}/records")->assertOk();
    $this->get("/zones/{$zone->id}/providers")->assertOk();
    $this->post("/zones/{$zone->id}/sync")->assertRedirect();

    $this->get("/zones/{$sibling->id}")->assertForbidden();
    $this->post("/zones/{$sibling->id}/sync")->assertForbidden();

    // Record management does not include the audit trail …
    $this->get("/zones/{$zone->id}/activity")->assertForbidden();
    $this->getJson("/zones/{$zone->id}/activity/data")->assertForbidden();
    // … nor attachments or the zone itself.
    $this->post("/zones/{$zone->id}/providers", attachmentPayload($provider))->assertForbidden();
    $this->put("/zones/{$zone->id}", ['name' => 'granted.dev'])->assertForbidden();
    $this->delete("/zones/{$zone->id}")->assertForbidden();
});

test('zone viewers get read-only access to their zone including activity', function () {
    $zone = DnsZone::factory()->create(['name' => 'granted.dev']);
    $sibling = DnsZone::factory()->create(['name' => 'sibling.dev']);

    $this->actingAs(grantedUser($zone));

    $this->get("/zones/{$zone->id}")->assertRedirect("/zones/{$zone->id}/records");
    $this->get("/zones/{$zone->id}/records")->assertOk();
    $this->get("/zones/{$zone->id}/providers")->assertOk();
    $this->get("/zones/{$zone->id}/activity")->assertOk();

    $this->get("/zones/{$sibling->id}")->assertForbidden();

    $this->post('/entries', entryPayload($zone))->assertForbidden();
    $this->post("/zones/{$zone->id}/sync")->assertForbidden();
    $this->put("/zones/{$zone->id}", ['name' => 'renamed.dev'])->assertForbidden();
    $this->delete("/zones/{$zone->id}")->assertForbidden();
});

test('zone provider managers manage attachments in their zone only', function () {
    $zone = DnsZone::factory()->create(['name' => 'granted.dev']);
    $sibling = DnsZone::factory()->create(['name' => 'sibling.dev']);
    $provider = Provider::factory()->cloudflare()->create();

    $this->actingAs(grantedUser($zone, 'providerManager'));

    // Any grant makes the zone visible.
    $this->get("/zones/{$zone->id}")->assertRedirect("/zones/{$zone->id}/records");
    $this->get("/zones/{$zone->id}/providers")->assertOk();

    $this->post("/zones/{$zone->id}/providers", attachmentPayload($provider))
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(ZoneProvider::where('dns_zone_id', $zone->id)->count())->toBe(1);

    $this->post("/zones/{$sibling->id}/providers", attachmentPayload($provider))->assertForbidden();

    // Attachment rights include neither records nor activity.
    $this->post('/entries', entryPayload($zone))->assertForbidden();
    $this->post("/zones/{$zone->id}/sync")->assertForbidden();
    $this->get("/zones/{$zone->id}/activity")->assertForbidden();
});

test('zone admins run their zone but cannot delete it', function () {
    $zone = DnsZone::factory()->create(['name' => 'granted.dev']);
    $sibling = DnsZone::factory()->create(['name' => 'sibling.dev']);
    $provider = Provider::factory()->cloudflare()->create();

    $this->actingAs(grantedUser($zone, 'admin'));

    $this->post('/entries', entryPayload($zone))->assertRedirect()->assertSessionHasNoErrors();
    $this->post("/zones/{$zone->id}/sync")->assertRedirect();
    $this->get("/zones/{$zone->id}/activity")->assertOk();
    $this->post("/zones/{$zone->id}/providers", attachmentPayload($provider))
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->put("/zones/{$zone->id}", ['name' => 'renamed.dev'])
        ->assertRedirect()->assertSessionHasNoErrors();

    // Deleting zones stays Super Admin only.
    $this->delete("/zones/{$zone->id}")->assertForbidden();

    // Nothing spills over to sibling zones.
    $this->put("/zones/{$sibling->id}", ['name' => 'nope.dev'])->assertForbidden();
});

test('zone roles combine as a union', function () {
    $zone = DnsZone::factory()->create();
    $provider = Provider::factory()->cloudflare()->create();

    $user = User::factory()->noRoles()->create();
    ZoneUser::factory()->create([
        'user_id' => $user->id,
        'dns_zone_id' => $zone->id,
        'roles' => [ZoneRole::ZoneDnsManager->value, ZoneRole::ZoneProviderManager->value],
    ]);

    $this->actingAs($user);

    $this->post('/entries', entryPayload($zone))->assertRedirect()->assertSessionHasNoErrors();
    $this->post("/zones/{$zone->id}/providers", attachmentPayload($provider))
        ->assertRedirect()->assertSessionHasNoErrors();

    // Neither role grants activity or zone settings.
    $this->get("/zones/{$zone->id}/activity")->assertForbidden();
    $this->put("/zones/{$zone->id}", ['name' => 'renamed.dev'])->assertForbidden();
});

// ── Entry authorization edges ───────────────────────────────────────────────

test('entry store treats a missing or unknown zone as validation, not authorization', function () {
    $this->actingAs(User::factory()->noRoles()->create());

    $this->postJson('/entries', ['name' => 'app', 'type' => 'A', 'content' => '10.0.0.1'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('dns_zone_id');

    $this->postJson('/entries', [
        'dns_zone_id' => 999999, 'name' => 'app', 'type' => 'A', 'content' => '10.0.0.1',
    ])->assertUnprocessable()->assertJsonValidationErrors('dns_zone_id');

    // A real zone the user cannot manage is authorization — 403.
    $zone = DnsZone::factory()->create();
    $this->postJson('/entries', entryPayload($zone))->assertForbidden();
});

test('super viewer bulk selections shrink to nothing instead of failing', function () {
    $entry = DnsEntry::factory()->create();

    $this->actingAs(User::factory()->superViewer()->create());

    // Route middleware is auth-only; the selection silently shrinks to the
    // zones where the actor manages records — nothing, for a Super Viewer.
    $this->post('/entries/bulk/sync', ['ids' => [$entry->id]])
        ->assertRedirect()
        ->assertSessionHas('success', fn ($message) => str_contains($message, '0 entries'));
    $this->patch('/entries/bulk', ['ids' => [$entry->id], 'set' => ['ttl' => 3600]])->assertRedirect();
    $this->delete('/entries/bulk', ['ids' => [$entry->id]])->assertRedirect();

    expect(DnsEntry::whereKey($entry->id)->exists())->toBeTrue()
        ->and($entry->refresh()->ttl)->toBeNull();
});

// ── User management ─────────────────────────────────────────────────────────
// Page rendering, role updates, and the self/escalation/last-SA guards live in
// UsersPageTest; zone access grants live in ZoneAccessTest.

// ── Provisioning ────────────────────────────────────────────────────────────

test('first oidc user becomes super admin, later users start with no roles', function () {
    $oidcUser = fn (string $sub, string $email) => (new SocialiteUser())->map([
        'id' => $sub, 'name' => 'U ' . $sub, 'email' => $email,
    ]);

    Socialite::shouldReceive('driver->user')->andReturn(
        $oidcUser('sub-1', 'first@example.com'),
        $oidcUser('sub-2', 'second@example.com'),
    );

    $this->get('/auth/callback?code=a&state=b');

    expect(User::sole()->roles)->toBe([Role::SuperAdmin->value]);

    $this->post('/logout');

    $this->get('/auth/callback?code=a&state=b');

    expect(User::where('email', 'second@example.com')->sole()->roles)->toBe([]);
});

// ── Shared props ────────────────────────────────────────────────────────────

test('permissions are shared with the frontend in the new auth shape', function () {
    $viewer = User::factory()->superViewer()->create();

    $this->actingAs($viewer)->get('/dashboard')->assertInertia(
        fn ($page) => $page
            ->where('auth.user.id', $viewer->id)
            ->where('auth.user.roles', [Role::SuperViewer->value])
            ->where('auth.can.createZones', false)
            ->where('auth.can.manageProviders', false)
            ->where('auth.can.viewProviders', true)
            ->where('auth.can.manageUsers', false)
            ->where('auth.can.viewUsers', true)
            ->where('auth.can.viewGlobalActivity', true)
            ->where('auth.can.hasZoneAccess', true)
    );

    $this->actingAs(User::factory()->create())->get('/dashboard')->assertInertia(
        fn ($page) => $page
            ->where('auth.can.createZones', true)
            ->where('auth.can.manageProviders', true)
            ->where('auth.can.viewProviders', true)
            ->where('auth.can.manageUsers', true)
            ->where('auth.can.viewUsers', true)
            ->where('auth.can.viewGlobalActivity', true)
            ->where('auth.can.hasZoneAccess', true)
    );

    $granted = grantedUser(DnsZone::factory()->create(), 'dnsManager');

    $this->actingAs($granted)->get('/dashboard')->assertInertia(
        fn ($page) => $page
            ->where('auth.can.createZones', false)
            ->where('auth.can.manageProviders', false)
            ->where('auth.can.viewProviders', false)
            ->where('auth.can.manageUsers', false)
            ->where('auth.can.viewUsers', false)
            ->where('auth.can.viewGlobalActivity', false)
            ->where('auth.can.hasZoneAccess', true)
    );

    $this->actingAs(User::factory()->noRoles()->create())->get('/dashboard')->assertInertia(
        fn ($page) => $page->where('auth.can.hasZoneAccess', false)
    );
});
