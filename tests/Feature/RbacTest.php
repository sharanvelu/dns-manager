<?php

use App\Enums\Role;
use App\Models\DnsEntry;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    Queue::fake();
});

function entryPayload(): array
{
    return ['name' => 'app.example.com', 'type' => 'A', 'content' => '10.0.0.1', 'proxied' => false];
}

function providerPayload(): array
{
    return [
        'name' => 'CF',
        'type' => 'cloudflare',
        'enabled' => true,
        'managed_record_types' => ['A'],
        'config' => ['api_token' => 'tok', 'zone_id' => 'z1'],
    ];
}

test('viewers can see pages but cannot mutate anything', function () {
    $viewer = User::factory()->viewer()->create();
    $entry = DnsEntry::factory()->create();
    $provider = Provider::factory()->cloudflare()->create();

    $this->actingAs($viewer);

    $this->get('/dashboard')->assertOk();
    $this->get('/entries')->assertOk();
    $this->get('/providers')->assertOk();

    $this->post('/entries', entryPayload())->assertForbidden();
    $this->put("/entries/{$entry->id}", entryPayload())->assertForbidden();
    $this->delete("/entries/{$entry->id}")->assertForbidden();
    $this->post("/entries/{$entry->id}/sync")->assertForbidden();
    $this->post('/entries/import', [])->assertForbidden();
    $this->get('/entries/import/sample')->assertForbidden();

    $this->post('/providers', providerPayload())->assertForbidden();
    $this->put("/providers/{$provider->id}", providerPayload())->assertForbidden();
    $this->delete("/providers/{$provider->id}")->assertForbidden();
    $this->post('/providers/test', ['type' => 'cloudflare', 'config' => []])->assertForbidden();
    $this->post("/providers/{$provider->id}/check")->assertForbidden();

    $this->get('/settings/users')->assertForbidden();
});

test('dns managers can mutate entries but not providers or users', function () {
    $user = User::factory()->withRoles(Role::DnsManager)->create();

    $this->actingAs($user);

    $this->post('/entries', entryPayload())->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/providers', providerPayload())->assertForbidden();
    $this->get('/settings/users')->assertForbidden();
});

test('providers managers can mutate providers but not entries or users', function () {
    $user = User::factory()->withRoles(Role::ProvidersManager)->create();

    $this->actingAs($user);

    $this->post('/providers', providerPayload())->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/entries', entryPayload())->assertForbidden();
    $this->get('/settings/users')->assertForbidden();
});

test('roles combine as a union', function () {
    $user = User::factory()->withRoles(Role::DnsManager, Role::ProvidersManager)->create();

    $this->actingAs($user);

    $this->post('/entries', entryPayload())->assertRedirect();
    $this->post('/providers', providerPayload())->assertRedirect();
    $this->get('/settings/users')->assertForbidden();
});

test('super admins can manage users and see role options', function () {
    $admin = User::factory()->create();
    User::factory()->viewer()->create();

    $this->actingAs($admin)->get('/settings/users')->assertOk()->assertInertia(
        fn ($page) => $page->component('settings/users')->has('users', 2)->has('roles', 4)
    );
});

test('super admin can assign multiple roles to a user', function () {
    $admin = User::factory()->create();
    $user = User::factory()->viewer()->create();

    $this->actingAs($admin)
        ->put("/settings/users/{$user->id}", ['roles' => ['dns-manager', 'providers-manager']])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($user->refresh()->roles)->toEqualCanonicalizing(['dns-manager', 'providers-manager']);
});

test('unknown roles are rejected', function () {
    $admin = User::factory()->create();
    $user = User::factory()->viewer()->create();

    $this->actingAs($admin)
        ->put("/settings/users/{$user->id}", ['roles' => ['root']])
        ->assertSessionHasErrors('roles.0');
});

test('the last super admin cannot lose the role or be deleted', function () {
    $admin = User::factory()->create();
    $other = User::factory()->viewer()->create();

    $this->actingAs($admin)
        ->put("/settings/users/{$admin->id}", ['roles' => ['viewer']])
        ->assertSessionHasErrors('roles');

    expect($admin->refresh()->isSuperAdmin())->toBeTrue();

    // A second super admin unblocks the demotion.
    $other->update(['roles' => [Role::SuperAdmin->value]]);

    $this->actingAs($admin)
        ->put("/settings/users/{$admin->id}", ['roles' => ['viewer']])
        ->assertSessionHasNoErrors();
});

test('users cannot delete themselves and the last super admin cannot be deleted', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->delete("/settings/users/{$admin->id}")->assertSessionHasErrors('user');

    $secondAdmin = User::factory()->create();
    $this->actingAs($admin)->delete("/settings/users/{$secondAdmin->id}")->assertSessionHasNoErrors();
    expect(User::find($secondAdmin->id))->toBeNull();
});

test('first oidc user becomes super admin, later users become viewers', function () {
    $oidcUser = fn (string $sub, string $email) => (new SocialiteUser)->map([
        'id' => $sub, 'name' => 'U '.$sub, 'email' => $email,
    ]);

    Socialite::shouldReceive('driver->user')->andReturn(
        $oidcUser('sub-1', 'first@example.com'),
        $oidcUser('sub-2', 'second@example.com'),
    );

    $this->get('/auth/callback?code=a&state=b');

    expect(User::sole()->roles)->toBe([Role::SuperAdmin->value]);

    $this->post('/logout');

    $this->get('/auth/callback?code=a&state=b');

    expect(User::where('email', 'second@example.com')->sole()->roles)->toBe([Role::Viewer->value]);
});

test('permissions are shared with the frontend', function () {
    $user = User::factory()->withRoles(Role::DnsManager)->create();

    $this->actingAs($user)->get('/dashboard')->assertInertia(
        fn ($page) => $page
            ->where('auth.can.manageEntries', true)
            ->where('auth.can.manageProviders', false)
            ->where('auth.can.manageUsers', false)
    );
});
