<?php

declare(strict_types = 1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Facades\Password;
use App\Notifications\InviteNotification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ResetPasswordNotification;

function ssoOnlyUser(array $attributes = []): User
{
    return User::factory()->create(['password' => 'secret-pass', 'sso_only' => true] + $attributes);
}

test('an admin can switch SSO-only on and off while the password hash is kept', function () {
    $admin = User::factory()->create();
    $target = User::factory()->create(['password' => 'secret-pass']);
    $hash = $target->password;

    $this->actingAs($admin)->patch("/users/{$target->id}/sso-only", ['sso_only' => true])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect($target->refresh()->sso_only)->toBeTrue()->and($target->password)->toBe($hash);

    $this->actingAs($admin)->patch("/users/{$target->id}/sso-only", ['sso_only' => false])
        ->assertSessionHas('success');

    expect($target->refresh()->sso_only)->toBeFalse()->and($target->password)->toBe($hash);
    expect(Hash::check('secret-pass', $target->password))->toBeTrue();
});

test('changing the flag writes an auth event and the model audit row', function () {
    $admin = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)->patch("/users/{$target->id}/sso-only", ['sso_only' => true]);

    $auth = Activity::query()->where('log_name', 'auth')->where('event', 'sso-only-changed')->sole();
    expect($auth->causer_id)->toBe($admin->id)
        ->and($auth->subject_id)->toBe($target->id)
        ->and($auth->properties['sso_only'])->toBeTrue();

    $updated = Activity::query()->where('log_name', 'users')->where('event', 'updated')->sole();
    expect(data_get($updated->attribute_changes, 'attributes.sso_only'))->toBeTrue()
        ->and(data_get($updated->attribute_changes, 'old.sso_only'))->toBeFalse();
});

test('setting the same value again is a no-op', function () {
    $admin = User::factory()->create();
    $target = ssoOnlyUser();
    Activity::query()->delete();

    $this->actingAs($admin)->patch("/users/{$target->id}/sso-only", ['sso_only' => true])
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('success');

    expect(Activity::query()->count())->toBe(0);
});

test('the value is required and boolean', function () {
    $admin = User::factory()->create();
    $target = User::factory()->create();

    $this->actingAs($admin)->patch("/users/{$target->id}/sso-only", [])->assertSessionHasErrors('sso_only');
    $this->actingAs($admin)->patch("/users/{$target->id}/sso-only", ['sso_only' => 'maybe'])->assertSessionHasErrors('sso_only');
});

test('a user admin cannot change their own account but can change others', function () {
    $userAdmin = User::factory()->userAdmin()->create();
    $other = User::factory()->create();

    $this->actingAs($userAdmin)->patch("/users/{$userAdmin->id}/sso-only", ['sso_only' => true])
        ->assertSessionHasErrors('sso_only');
    expect($userAdmin->refresh()->sso_only)->toBeFalse();

    $this->actingAs($userAdmin)->patch("/users/{$other->id}/sso-only", ['sso_only' => true])
        ->assertSessionHasNoErrors();
    expect($other->refresh()->sso_only)->toBeTrue();
});

test('a super admin may set the flag on themselves', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)->patch("/users/{$admin->id}/sso-only", ['sso_only' => true])->assertSessionHasNoErrors();

    expect($admin->refresh()->sso_only)->toBeTrue();
});

test('users who cannot manage users are forbidden', function () {
    $target = User::factory()->create();

    $this->actingAs(User::factory()->superViewer()->create())
        ->patch("/users/{$target->id}/sso-only", ['sso_only' => true])->assertForbidden();
    $this->actingAs(User::factory()->noRoles()->create())
        ->patch("/users/{$target->id}/sso-only", ['sso_only' => true])->assertForbidden();

    expect($target->refresh()->sso_only)->toBeFalse();
});

test('the toggle is unavailable when SSO is disabled', function () {
    config(['auth.sso_enabled' => false]);
    $target = User::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch("/users/{$target->id}/sso-only", ['sso_only' => true])->assertNotFound();
});

test('an SSO-only user cannot sign in with a password and it looks like a wrong password', function () {
    $user = ssoOnlyUser();
    $other = User::factory()->create(['password' => 'secret-pass']);

    $blocked = $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass']);
    $wrong = $this->post('/login', ['email' => $other->email, 'password' => 'bad-pass']);

    $blocked->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toBe('These credentials do not match our records.');
    $this->assertGuest();

    expect(Activity::query()->where('event', 'login-failed')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'login')->count())->toBe(0);
    expect(RateLimiter::attempts(strtolower($user->email) . '|127.0.0.1'))->toBe(1);

    RateLimiter::clear(strtolower($user->email) . '|127.0.0.1');
    RateLimiter::clear(strtolower($other->email) . '|127.0.0.1');
});

test('the flag is inert while SSO is disabled', function () {
    config(['auth.sso_enabled' => false]);
    $user = ssoOnlyUser();

    expect($user->passwordLoginAllowed())->toBeTrue();
    $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($user);
});

test('forgot password sends nothing for an SSO-only user and answers identically', function () {
    Notification::fake();
    $user = ssoOnlyUser();
    $normal = User::factory()->create();

    $blocked = $this->post('/forgot-password', ['email' => $user->email]);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

    expect($blocked->getSession()->get('status'))->toBe($unknown->getSession()->get('status'));
    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'password-reset-requested')->count())->toBe(0);

    $this->post('/forgot-password', ['email' => $normal->email]);
    Notification::assertSentTo($normal, ResetPasswordNotification::class);
    expect(Activity::query()->where('event', 'password-reset-requested')->count())->toBe(1);
});

test('reset tokens issued before the flag was set stop working', function () {
    $user = User::factory()->create(['password' => 'old-password']);
    $token = Password::broker('users')->createToken($user);
    $user->forceFill(['sso_only' => true])->save();
    $hash = $user->password;

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertSessionHasErrors('email');

    expect($user->refresh()->password)->toBe($hash);
});

test('invite tokens issued before the flag was set stop working', function () {
    $user = User::factory()->create(['password' => null]);
    $token = Password::broker('invites')->createToken($user);
    $user->forceFill(['sso_only' => true])->save();

    $this->post('/set-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'first-password',
        'password_confirmation' => 'first-password',
    ])->assertSessionHasErrors('email');

    expect($user->refresh()->password)->toBeNull();
});

test('a user can be created as SSO-only without an invite', function () {
    Notification::fake();
    $admin = User::factory()->create();

    $this->actingAs($admin)->post('/users', ['name' => 'Sso Person', 'email' => 'sso@example.com', 'roles' => [], 'sso_only' => true])
        ->assertSessionHasNoErrors();

    $created = User::where('email', 'sso@example.com')->sole();
    expect($created->sso_only)->toBeTrue()->and($created->isPendingInvite())->toBeFalse();
    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'invite-sent')->count())->toBe(0);
});

test('with SSO disabled the create flag is ignored and the invite is sent', function () {
    config(['auth.sso_enabled' => false]);
    Notification::fake();
    $admin = User::factory()->create();

    $this->actingAs($admin)->post('/users', ['name' => 'Plain Person', 'email' => 'plain@example.com', 'roles' => [], 'sso_only' => true]);

    $created = User::where('email', 'plain@example.com')->sole();
    expect($created->sso_only)->toBeFalse();
    Notification::assertSentTo($created, InviteNotification::class);
});

test('resending an invite to an SSO-only user is refused', function () {
    Notification::fake();
    $target = User::factory()->create(['password' => null, 'sso_only' => true]);

    $this->actingAs(User::factory()->create())->post("/users/{$target->id}/invite")->assertSessionHasErrors('user');

    Notification::assertNothingSent();
});

test('profile exposes the effective flag and password changes are rejected', function () {
    $user = ssoOnlyUser();

    $this->actingAs($user)->get('/profile')->assertInertia(fn ($page) => $page->where('ssoOnly', true));

    $this->actingAs($user)->put('/profile/password', [
        'current_password' => 'secret-pass',
        'password' => 'brand-new-pass',
        'password_confirmation' => 'brand-new-pass',
    ])->assertSessionHasErrors('password');
    expect(Hash::check('secret-pass', $user->refresh()->password))->toBeTrue();

    config(['auth.sso_enabled' => false]);
    $this->actingAs($user)->get('/profile')->assertInertia(fn ($page) => $page->where('ssoOnly', false));
});

test('users index and show expose the flag', function () {
    $admin = User::factory()->create();
    $target = ssoOnlyUser(['oidc_sub' => 'sub-1']);

    $this->actingAs($admin)->get('/users')->assertInertia(
        fn ($page) => $page->where('ssoEnabled', true)->where('users', fn ($users) => collect($users)->firstWhere('id', $target->id)['ssoOnly'] === true)
    );

    $this->actingAs($admin)->get("/users/{$target->id}")->assertInertia(
        fn ($page) => $page->where('ssoEnabled', true)->where('managedUser.ssoOnly', true)->where('managedUser.ssoLinked', true)
    );
});
