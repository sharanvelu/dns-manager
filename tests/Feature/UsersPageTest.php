<?php

use App\Enums\Role;
use App\Enums\ZoneRole;
use App\Models\DnsZone;
use App\Models\User;
use App\Models\ZoneUser;
use Spatie\Activitylog\Models\Activity;

// ── Index ───────────────────────────────────────────────────────────────────

test('the users index lists every user with roles and zone-grant counts', function () {
    $admin = User::factory()->create(['name' => 'Alice Admin']);
    $granted = User::factory()->noRoles()->create(['name' => 'Bob Granted']);
    ZoneUser::factory()->count(2)->create(['user_id' => $granted->id]);

    $this->actingAs($admin)->get('/users')->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('users/index')
            ->has('users', 2)
            ->where('users.0.name', 'Alice Admin')
            ->where('users.0.roles', [Role::SuperAdmin->value])
            ->where('users.0.zoneGrantsCount', 0)
            ->where('users.1.name', 'Bob Granted')
            ->where('users.1.zoneGrantsCount', 2)
            ->has('users.1.email')
            ->has('users.1.createdAt')
            ->where('canManage', true)
    );
});

test('super admins can see the users index with role options on the detail page', function () {
    $admin = User::factory()->create();
    User::factory()->superViewer()->create();

    $this->actingAs($admin)->get('/users')->assertOk()->assertInertia(
        fn ($page) => $page->component('users/index')->has('users', 2)
    );
});

// ── Show ────────────────────────────────────────────────────────────────────

test('the user detail page carries grants, role options, and grantable zones', function () {
    $admin = User::factory()->create();
    $user = User::factory()->noRoles()->create();

    $beta = DnsZone::factory()->create(['name' => 'beta.dev']);
    $alpha = DnsZone::factory()->create(['name' => 'alpha.dev']);
    $spare = DnsZone::factory()->create(['name' => 'spare.dev']);

    ZoneUser::factory()->dnsManager()->create(['user_id' => $user->id, 'dns_zone_id' => $beta->id]);
    ZoneUser::factory()->viewer()->create(['user_id' => $user->id, 'dns_zone_id' => $alpha->id]);

    $this->actingAs($admin)->get("/users/{$user->id}")->assertOk()->assertInertia(
        fn ($page) => $page
            ->component('users/show')
            ->where('managedUser.id', $user->id)
            ->where('managedUser.name', $user->name)
            ->where('managedUser.email', $user->email)
            ->where('managedUser.roles', [])
            ->has('managedUser.createdAt')
            // Grants sorted by zone name.
            ->has('grants', 2)
            ->where('grants.0.zoneId', $alpha->id)
            ->where('grants.0.zoneName', 'alpha.dev')
            ->where('grants.0.roles', [ZoneRole::ZoneViewer->value])
            ->where('grants.1.zoneName', 'beta.dev')
            ->has('roleOptions', 3)
            ->has('roleOptions.0.value')
            ->has('roleOptions.0.label')
            ->has('roleOptions.0.description')
            ->has('zoneRoleOptions', 4)
            // Only the zone without a grant is grantable.
            ->has('grantableZones', 1)
            ->where('grantableZones.0.id', $spare->id)
            ->where('grantableZones.0.name', 'spare.dev')
            ->where('canManage', true)
    );
});

test('super viewers see the detail page read-only with no grantable zones', function () {
    $user = User::factory()->noRoles()->create();
    DnsZone::factory()->create();

    $this->actingAs(User::factory()->superViewer()->create())
        ->get("/users/{$user->id}")->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('users/show')
                ->where('canManage', false)
                ->has('grantableZones', 0)
        );
});

test('a user admin viewing their own page gets canManage false', function () {
    $userAdmin = User::factory()->userAdmin()->create();
    DnsZone::factory()->create();

    $this->actingAs($userAdmin)->get("/users/{$userAdmin->id}")->assertOk()->assertInertia(
        fn ($page) => $page->where('canManage', false)->has('grantableZones', 0)
    );

    $other = User::factory()->noRoles()->create();

    $this->actingAs($userAdmin)->get("/users/{$other->id}")->assertOk()->assertInertia(
        fn ($page) => $page->where('canManage', true)->has('grantableZones', 1)
    );
});

// ── View gates ──────────────────────────────────────────────────────────────

test('user admins and super viewers may view users, zone-only users may not', function () {
    $target = User::factory()->noRoles()->create();

    $this->actingAs(User::factory()->userAdmin()->create());
    $this->get('/users')->assertOk();
    $this->get("/users/{$target->id}")->assertOk();

    $this->actingAs(User::factory()->superViewer()->create());
    $this->get('/users')->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false));
    $this->get("/users/{$target->id}")->assertOk();

    $zoneOnly = User::factory()->noRoles()->create();
    ZoneUser::factory()->admin()->create(['user_id' => $zoneOnly->id]);

    $this->actingAs($zoneOnly);
    $this->get('/users')->assertForbidden();
    $this->get("/users/{$target->id}")->assertForbidden();
});

// ── Update ──────────────────────────────────────────────────────────────────

test('super admin can assign multiple roles to a user', function () {
    $admin = User::factory()->create();
    $user = User::factory()->noRoles()->create();

    $this->actingAs($admin)
        ->put("/users/{$user->id}", ['roles' => ['super-viewer', 'user-admin']])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($user->refresh()->roles)->toEqualCanonicalizing(['super-viewer', 'user-admin']);
});

test('a user can be stripped down to zero global roles', function () {
    $admin = User::factory()->create();
    $user = User::factory()->superViewer()->create();

    $this->actingAs($admin)
        ->put("/users/{$user->id}", ['roles' => []])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($user->refresh()->roles)->toBe([]);
});

test('unknown and legacy roles are rejected', function () {
    $admin = User::factory()->create();
    $user = User::factory()->noRoles()->create();

    $this->actingAs($admin);

    foreach (['root', 'viewer', 'dns-manager', 'providers-manager'] as $role) {
        $this->put("/users/{$user->id}", ['roles' => [$role]])
            ->assertSessionHasErrors('roles.0');
    }

    expect($user->refresh()->roles)->toBe([]);
});

test('a user admin cannot change their own account', function () {
    $userAdmin = User::factory()->userAdmin()->create();
    User::factory()->create();

    $this->actingAs($userAdmin)
        ->put("/users/{$userAdmin->id}", ['roles' => ['user-admin', 'super-viewer']])
        ->assertSessionHasErrors('roles');

    expect($userAdmin->refresh()->roles)->toBe([Role::UserAdmin->value]);
});

test('a super admin who is also user admin may change themselves', function () {
    $admin = User::factory()->withRoles(Role::SuperAdmin, Role::UserAdmin)->create();
    User::factory()->create();

    $this->actingAs($admin)
        ->put("/users/{$admin->id}", ['roles' => ['super-admin']])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($admin->refresh()->roles)->toBe(['super-admin']);
});

test('only a super admin can grant the super admin role', function () {
    $userAdmin = User::factory()->userAdmin()->create();
    $target = User::factory()->noRoles()->create();

    $this->actingAs($userAdmin)
        ->put("/users/{$target->id}", ['roles' => ['super-admin']])
        ->assertSessionHasErrors('roles');

    expect($target->refresh()->roles)->toBe([]);
});

test('only a super admin can revoke the super admin role', function () {
    User::factory()->create();
    $userAdmin = User::factory()->userAdmin()->create();
    $superAdmin = User::factory()->create();

    $this->actingAs($userAdmin)
        ->put("/users/{$superAdmin->id}", ['roles' => ['super-viewer']])
        ->assertSessionHasErrors('roles');

    expect($superAdmin->refresh()->isSuperAdmin())->toBeTrue();

    // Keeping the super-admin role while changing others is fine.
    $this->actingAs($userAdmin)
        ->put("/users/{$superAdmin->id}", ['roles' => ['super-admin', 'super-viewer']])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($superAdmin->refresh()->roles)->toEqualCanonicalizing(['super-admin', 'super-viewer']);
});

test('super admins can grant and revoke the super admin role', function () {
    $admin = User::factory()->create();
    $target = User::factory()->noRoles()->create();

    $this->actingAs($admin)
        ->put("/users/{$target->id}", ['roles' => ['super-admin']])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($target->refresh()->isSuperAdmin())->toBeTrue();

    $this->actingAs($admin)
        ->put("/users/{$target->id}", ['roles' => []])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($target->refresh()->roles)->toBe([]);
});

test('the last super admin cannot lose the role', function () {
    $admin = User::factory()->create();
    $other = User::factory()->superViewer()->create();

    $this->actingAs($admin)
        ->put("/users/{$admin->id}", ['roles' => ['super-viewer']])
        ->assertSessionHasErrors('roles');

    expect($admin->refresh()->isSuperAdmin())->toBeTrue();

    // A second super admin unblocks the demotion.
    $other->update(['roles' => [Role::SuperAdmin->value]]);

    $this->actingAs($admin)
        ->put("/users/{$admin->id}", ['roles' => ['super-viewer']])
        ->assertSessionHasNoErrors();
});

test('role changes are written to the activity log', function () {
    $admin = User::factory()->create();
    $user = User::factory()->noRoles()->create();

    $this->actingAs($admin)
        ->put("/users/{$user->id}", ['roles' => ['super-viewer']])
        ->assertRedirect()->assertSessionHasNoErrors();

    $activity = Activity::query()->where('log_name', 'users')->where('event', 'updated')->sole();

    expect($activity->causer_id)->toEqual($admin->id)
        ->and($activity->subject_type)->toBe('user')
        ->and($activity->subject_id)->toEqual($user->id)
        ->and(data_get($activity->attribute_changes, 'attributes.roles'))->toBe(['super-viewer'])
        ->and(data_get($activity->attribute_changes, 'old.roles'))->toBe([]);
});

// ── Destroy ─────────────────────────────────────────────────────────────────

test('users cannot delete themselves and the last super admin cannot be deleted', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->delete("/users/{$admin->id}")->assertSessionHasErrors('user');

    // Deleting a sole super admin via another account is also blocked.
    $userAdmin = User::factory()->userAdmin()->create();
    $this->actingAs($userAdmin)->delete("/users/{$admin->id}")->assertSessionHasErrors('user');

    expect(User::find($admin->id))->not->toBeNull();
});

test('deleting a user redirects to the users index', function () {
    $admin = User::factory()->create();
    $target = User::factory()->noRoles()->create();

    $this->actingAs($admin)
        ->delete("/users/{$target->id}")
        ->assertRedirect('/users')
        ->assertSessionHasNoErrors();

    expect(User::find($target->id))->toBeNull();
});

test('mutations stay behind manage-users', function () {
    $target = User::factory()->noRoles()->create();
    User::factory()->create();

    $this->actingAs(User::factory()->superViewer()->create());
    $this->put("/users/{$target->id}", ['roles' => ['super-viewer']])->assertForbidden();
    $this->delete("/users/{$target->id}")->assertForbidden();

    $zoneOnly = User::factory()->noRoles()->create();
    ZoneUser::factory()->admin()->create(['user_id' => $zoneOnly->id]);

    $this->actingAs($zoneOnly);
    $this->put("/users/{$target->id}", ['roles' => ['super-viewer']])->assertForbidden();
    $this->delete("/users/{$target->id}")->assertForbidden();
});
