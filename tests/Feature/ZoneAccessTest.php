<?php

declare(strict_types = 1);

use App\Models\User;
use App\Enums\ZoneRole;
use App\Models\DnsZone;
use App\Models\ZoneUser;
use Spatie\Activitylog\Models\Activity;

/** A user whose ONLY access is a single grant on the given zone. */
function zoneGrantedActor(DnsZone $zone, string $state = 'admin'): User
{
    $actor = User::factory()->noRoles()->create();

    ZoneUser::factory()->{$state}()->create(['user_id' => $actor->id, 'dns_zone_id' => $zone->id]);

    return $actor;
}

// ── Access page ─────────────────────────────────────────────────────────────

test('the access page renders grants, role options, and abilities for a super admin', function () {
    $zone = DnsZone::factory()->create(['name' => 'granted.dev']);

    $bella = User::factory()->noRoles()->create(['name' => 'Bella']);
    $adam = User::factory()->noRoles()->create(['name' => 'adam']);
    ZoneUser::factory()->viewer()->create(['user_id' => $bella->id, 'dns_zone_id' => $zone->id]);
    ZoneUser::factory()->admin()->create(['user_id' => $adam->id, 'dns_zone_id' => $zone->id]);

    // Grants on other zones never leak into this page.
    ZoneUser::factory()->create(['dns_zone_id' => DnsZone::factory()->create()->id]);

    $this->actingAs(User::factory()->create())
        ->get("/zones/{$zone->id}/access")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('zones/access')
                ->where('zone.name', 'granted.dev')
                ->has('grants', 2)
                // Sorted by user name, case-insensitively.
                ->where('grants.0.userId', $adam->id)
                ->where('grants.0.roles', [ZoneRole::ZoneAdmin->value])
                ->where('grants.1.userName', 'Bella')
                ->where('grants.1.userEmail', $bella->email)
                ->has('grants.1.createdAt')
                ->has('zoneRoleOptions', count(ZoneRole::cases()))
                ->where('zoneCan.viewZone', true)
                ->where('zoneCan.viewAccess', true)
                ->where('zoneCan.manageAccess', true)
                ->where('canGrantZoneAdmin', true)
        );
});

test('grantable users lists only ungranted users, and only for managers', function () {
    $zone = DnsZone::factory()->create();

    $granted = User::factory()->noRoles()->create();
    ZoneUser::factory()->viewer()->create(['user_id' => $granted->id, 'dns_zone_id' => $zone->id]);
    $candidate = User::factory()->noRoles()->create();

    foreach ([
        User::factory()->create(),
        User::factory()->userAdmin()->create(),
        zoneGrantedActor($zone),
    ] as $actor) {
        $response = $this->actingAs($actor)->get("/zones/{$zone->id}/access")->assertOk();

        $ids = collect($response->viewData('page')['props']['grantableUsers'])->pluck('id');

        expect($ids)->toContain($candidate->id)
            ->and($ids)->not->toContain($granted->id);
    }

    // Super Viewers read the page but never receive grant candidates.
    $this->actingAs(User::factory()->superViewer()->create())
        ->get("/zones/{$zone->id}/access")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('grantableUsers', []));
});

test('only super admins and user admins may grant zone-admin from the access page', function () {
    $zone = DnsZone::factory()->create();

    foreach ([User::factory()->create(), User::factory()->userAdmin()->create()] as $actor) {
        $this->actingAs($actor)
            ->get("/zones/{$zone->id}/access")
            ->assertInertia(fn ($page) => $page->where('canGrantZoneAdmin', true));
    }

    $this->actingAs(zoneGrantedActor($zone))
        ->get("/zones/{$zone->id}/access")
        ->assertInertia(fn ($page) => $page->where('canGrantZoneAdmin', false));
});

test('the access page sits behind the viewAccess policy', function () {
    $zone = DnsZone::factory()->create();
    $otherZone = DnsZone::factory()->create();

    foreach ([
        zoneGrantedActor($zone, 'dnsManager'),
        zoneGrantedActor($zone, 'viewer'),
        zoneGrantedActor($zone, 'providerManager'),
        User::factory()->noRoles()->create(),
        zoneGrantedActor($otherZone),
    ] as $actor) {
        $this->actingAs($actor)->get("/zones/{$zone->id}/access")->assertForbidden();
    }

    // Super Viewers (read-only), User Admins, and this zone's Zone Admins may look.
    $this->actingAs(User::factory()->superViewer()->create())->get("/zones/{$zone->id}/access")->assertOk();
    $this->actingAs(User::factory()->userAdmin()->create())->get("/zones/{$zone->id}/access")->assertOk();
    $this->actingAs(zoneGrantedActor($zone))->get("/zones/{$zone->id}/access")->assertOk();
});

test('the access page tells the tabs whether the zone itself is visible', function () {
    $zone = DnsZone::factory()->create();

    // A user-admin may open the Access tab but not the Records/Providers tabs.
    $this->actingAs(User::factory()->userAdmin()->create())
        ->get("/zones/{$zone->id}/access")
        ->assertInertia(
            fn ($page) => $page
                ->where('zoneCan.viewZone', false)
                ->where('zoneCan.viewAccess', true)
                ->where('zoneCan.manageAccess', true)
        );

    $this->actingAs(zoneGrantedActor($zone))
        ->get("/zones/{$zone->id}/access")
        ->assertInertia(fn ($page) => $page->where('zoneCan.viewZone', true));
});

// ── Upsert ──────────────────────────────────────────────────────────────────

test('granting zone access creates the grant and logs a zone-access-granted event', function () {
    $zone = DnsZone::factory()->create(['name' => 'granted.dev']);
    $user = User::factory()->noRoles()->create();
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->put("/zones/{$zone->id}/access/{$user->id}", [
            'roles' => [ZoneRole::ZoneViewer->value, ZoneRole::ZoneDnsManager->value],
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    $grant = ZoneUser::query()->where('dns_zone_id', $zone->id)->where('user_id', $user->id)->sole();

    expect($grant->roles)->toEqualCanonicalizing([ZoneRole::ZoneViewer->value, ZoneRole::ZoneDnsManager->value]);

    $activity = Activity::query()->where('log_name', 'users')->where('event', 'zone-access-granted')->sole();

    expect($activity->causer_id)->toEqual($admin->id)
        ->and($activity->subject_type)->toBe('zone-grant')
        ->and($activity->subject_id)->toEqual($grant->id)
        ->and(data_get($activity->properties, 'user'))->toBe($user->name)
        ->and(data_get($activity->properties, 'zone'))->toBe('granted.dev')
        ->and(data_get($activity->properties, 'roles'))->toEqualCanonicalizing([ZoneRole::ZoneViewer->value, ZoneRole::ZoneDnsManager->value]);
});

test('updating an existing grant logs a zone-access-updated event', function () {
    $zone = DnsZone::factory()->create();
    $user = User::factory()->noRoles()->create();
    $grant = ZoneUser::factory()->viewer()->create(['user_id' => $user->id, 'dns_zone_id' => $zone->id]);

    $this->actingAs(User::factory()->create())
        ->put("/zones/{$zone->id}/access/{$user->id}", ['roles' => [ZoneRole::ZoneDnsManager->value]])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($grant->refresh()->roles)->toBe([ZoneRole::ZoneDnsManager->value])
        ->and(ZoneUser::query()->where('dns_zone_id', $zone->id)->where('user_id', $user->id)->count())->toBe(1);

    expect(Activity::query()->where('log_name', 'users')->where('event', 'zone-access-updated')->exists())->toBeTrue();
});

test('duplicate roles are deduped before saving', function () {
    $zone = DnsZone::factory()->create();
    $user = User::factory()->noRoles()->create();

    $this->actingAs(User::factory()->create())
        ->put("/zones/{$zone->id}/access/{$user->id}", [
            'roles' => [ZoneRole::ZoneViewer->value, ZoneRole::ZoneViewer->value],
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(ZoneUser::query()->where('user_id', $user->id)->sole()->roles)->toBe([ZoneRole::ZoneViewer->value]);
});

// ── Destroy ─────────────────────────────────────────────────────────────────

test('revoking zone access deletes the grant and logs a zone-access-revoked event', function () {
    $zone = DnsZone::factory()->create(['name' => 'granted.dev']);
    $user = User::factory()->noRoles()->create();
    ZoneUser::factory()->viewer()->create(['user_id' => $user->id, 'dns_zone_id' => $zone->id]);

    $this->actingAs(User::factory()->create())
        ->delete("/zones/{$zone->id}/access/{$user->id}")
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(ZoneUser::query()->where('dns_zone_id', $zone->id)->where('user_id', $user->id)->exists())->toBeFalse();

    $activity = Activity::query()->where('log_name', 'users')->where('event', 'zone-access-revoked')->sole();

    expect(data_get($activity->properties, 'user'))->toBe($user->name)
        ->and(data_get($activity->properties, 'zone'))->toBe('granted.dev');
});

test('revoking a grant that does not exist is a 404', function () {
    $zone = DnsZone::factory()->create();
    $user = User::factory()->noRoles()->create();

    $this->actingAs(User::factory()->create())
        ->delete("/zones/{$zone->id}/access/{$user->id}")
        ->assertNotFound();
});

// ── Validation ──────────────────────────────────────────────────────────────

test('zone access requires at least one valid zone role', function () {
    $zone = DnsZone::factory()->create();
    $user = User::factory()->noRoles()->create();

    $this->actingAs(User::factory()->create());

    $this->put("/zones/{$zone->id}/access/{$user->id}", ['roles' => []])
        ->assertSessionHasErrors('roles');

    $this->put("/zones/{$zone->id}/access/{$user->id}", ['roles' => ['super-admin']])
        ->assertSessionHasErrors('roles.0');

    $this->put("/zones/{$zone->id}/access/{$user->id}", ['roles' => ['nonsense']])
        ->assertSessionHasErrors('roles.0');

    expect(ZoneUser::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

// ── Authorization matrix ────────────────────────────────────────────────────

test('super admins, user admins, and the zone\'s zone admins may manage access', function () {
    $zone = DnsZone::factory()->create();
    $target = User::factory()->noRoles()->create();

    foreach ([
        User::factory()->create(),
        User::factory()->userAdmin()->create(),
        zoneGrantedActor($zone),
    ] as $actor) {
        $this->actingAs($actor)
            ->put("/zones/{$zone->id}/access/{$target->id}", ['roles' => [ZoneRole::ZoneViewer->value]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($actor)
            ->delete("/zones/{$zone->id}/access/{$target->id}")
            ->assertRedirect()->assertSessionHasNoErrors();
    }
});

test('zone admins of another zone, dns managers, and super viewers may not manage access', function () {
    $zone = DnsZone::factory()->create();
    $otherZone = DnsZone::factory()->create();
    $target = User::factory()->noRoles()->create();
    ZoneUser::factory()->viewer()->create(['user_id' => $target->id, 'dns_zone_id' => $zone->id]);

    foreach ([
        zoneGrantedActor($otherZone),
        zoneGrantedActor($zone, 'dnsManager'),
        User::factory()->superViewer()->create(),
    ] as $actor) {
        $this->actingAs($actor)
            ->put("/zones/{$zone->id}/access/{$target->id}", ['roles' => [ZoneRole::ZoneViewer->value]])
            ->assertForbidden();

        $this->actingAs($actor)
            ->delete("/zones/{$zone->id}/access/{$target->id}")
            ->assertForbidden();
    }

    expect($target->zoneGrants()->sole()->roles)->toBe([ZoneRole::ZoneViewer->value]);
});

// ── Zone-admin actor restrictions ───────────────────────────────────────────

test('a zone admin cannot change or revoke their own access', function () {
    $zone = DnsZone::factory()->create();
    $actor = zoneGrantedActor($zone);

    $this->actingAs($actor);

    $this->put("/zones/{$zone->id}/access/{$actor->id}", ['roles' => [ZoneRole::ZoneViewer->value]])
        ->assertForbidden();
    $this->delete("/zones/{$zone->id}/access/{$actor->id}")
        ->assertForbidden();

    expect($actor->zoneGrants()->sole()->roles)->toBe([ZoneRole::ZoneAdmin->value]);
});

test('a zone admin cannot touch grants that contain zone-admin', function () {
    $zone = DnsZone::factory()->create();
    $actor = zoneGrantedActor($zone);

    $peer = User::factory()->noRoles()->create();
    ZoneUser::factory()->admin()->create(['user_id' => $peer->id, 'dns_zone_id' => $zone->id]);

    $this->actingAs($actor);

    $this->put("/zones/{$zone->id}/access/{$peer->id}", ['roles' => [ZoneRole::ZoneViewer->value]])
        ->assertForbidden();
    $this->delete("/zones/{$zone->id}/access/{$peer->id}")
        ->assertForbidden();

    expect($peer->zoneGrants()->sole()->roles)->toBe([ZoneRole::ZoneAdmin->value]);
});

test('a zone admin cannot grant zone-admin to anyone', function () {
    $zone = DnsZone::factory()->create();
    $actor = zoneGrantedActor($zone);
    $target = User::factory()->noRoles()->create();

    $this->actingAs($actor)
        ->put("/zones/{$zone->id}/access/{$target->id}", ['roles' => [ZoneRole::ZoneAdmin->value]])
        ->assertForbidden();

    expect(ZoneUser::query()->where('user_id', $target->id)->exists())->toBeFalse();
});

test('super admins and user admins may do everything a zone admin cannot', function () {
    $zone = DnsZone::factory()->create();

    foreach ([User::factory()->create(), User::factory()->userAdmin()->create()] as $actor) {
        $peer = User::factory()->noRoles()->create();
        ZoneUser::factory()->admin()->create(['user_id' => $peer->id, 'dns_zone_id' => $zone->id]);
        $target = User::factory()->noRoles()->create();

        $this->actingAs($actor);

        // Mint a new zone admin.
        $this->put("/zones/{$zone->id}/access/{$target->id}", ['roles' => [ZoneRole::ZoneAdmin->value]])
            ->assertRedirect()->assertSessionHasNoErrors();

        // Downgrade an existing zone admin.
        $this->put("/zones/{$zone->id}/access/{$peer->id}", ['roles' => [ZoneRole::ZoneViewer->value]])
            ->assertRedirect()->assertSessionHasNoErrors();
        expect($peer->zoneGrants()->sole()->roles)->toBe([ZoneRole::ZoneViewer->value]);

        // And revoke grants entirely.
        $this->delete("/zones/{$zone->id}/access/{$peer->id}")
            ->assertRedirect()->assertSessionHasNoErrors();
        expect($peer->zoneGrants()->exists())->toBeFalse();
    }
});
