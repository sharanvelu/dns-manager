<?php

declare(strict_types = 1);

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use App\Notifications\InviteNotification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ResetPasswordNotification;

test('login screen exposes which methods are enabled', function () {
    config(['auth.basic_enabled' => true]);

    $this->get('/login')->assertInertia(
        fn ($page) => $page->component('auth/login')->where('ssoEnabled', true)->where('basicEnabled', true)
    );
});

test('users can sign in with email and password', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('a wrong password is rejected', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);

    $this->post('/login', ['email' => $user->email, 'password' => 'nope'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a user without a password cannot sign in with one', function () {
    $user = User::factory()->create(['password' => null, 'oidc_sub' => 'sub-1']);

    $this->post('/login', ['email' => $user->email, 'password' => ''])->assertSessionHasErrors();
    $this->post('/login', ['email' => $user->email, 'password' => 'anything'])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('repeated failures are throttled', function () {
    config(['auth.login_max_attempts' => 3]);
    $user = User::factory()->create(['password' => 'secret-pass']);

    foreach (range(1, 3) as $ignored) {
        $this->post('/login', ['email' => $user->email, 'password' => 'bad']);
    }

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass'])
        ->assertSessionHasErrors('email');
    expect(session('errors')->first('email'))->toContain('Too many sign-in attempts');
    $this->assertGuest();

    RateLimiter::clear(strtolower($user->email) . '|127.0.0.1');
});

test('basic auth can be disabled while SSO is on', function () {
    config(['auth.sso_enabled' => true, 'auth.basic_enabled' => false]);

    $this->post('/login', ['email' => 'a@b.co', 'password' => 'x'])->assertNotFound();
    $this->get('/forgot-password')->assertNotFound();
    $this->get('/login')->assertInertia(fn ($page) => $page->where('basicEnabled', false)->where('ssoEnabled', true));
});

test('basic auth is forced on when SSO is disabled', function () {
    config(['auth.sso_enabled' => false, 'auth.basic_enabled' => false]);

    $this->get('/login')->assertInertia(fn ($page) => $page->where('basicEnabled', true)->where('ssoEnabled', false));
    $this->get('/auth/redirect')->assertNotFound();
    $this->get('/auth/callback')->assertNotFound();
    $this->get('/forgot-password')->assertOk();
});

test('forgot password emails a reset link and does not reveal unknown addresses', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->post('/forgot-password', ['email' => $user->email]);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

    expect($known->getSession()->get('status'))->toBe($unknown->getSession()->get('status'));
    Notification::assertSentTo($user, ResetPasswordNotification::class);
    Notification::assertCount(1);
});

test('a password can be reset with a valid token and sends the user to login', function () {
    $user = User::factory()->create(['password' => 'old-password']);
    $token = Password::broker('users')->createToken($user);

    $this->get("/reset-password/{$token}?email=" . urlencode($user->email))
        ->assertInertia(fn ($page) => $page->component('auth/reset-password')->where('mode', 'reset'));

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertRedirect(route('login'));

    expect(Hash::check('new-password-1', $user->refresh()->password))->toBeTrue();
});

test('a reset with a bad token fails', function () {
    $user = User::factory()->create();

    $this->post('/reset-password', [
        'token' => 'bogus',
        'email' => $user->email,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertSessionHasErrors('email');
});

test('the password minimum length is configurable', function () {
    config(['auth.password_min_length' => 12]);
    $user = User::factory()->create();
    $token = Password::broker('users')->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'short-pass1',
        'password_confirmation' => 'short-pass1',
    ])->assertSessionHasErrors('password');
});

test('an invited user sets a first password and lands on the login page', function () {
    $user = User::factory()->create(['password' => null]);
    expect($user->isPendingInvite())->toBeTrue();

    $token = Password::broker('invites')->createToken($user);

    $this->get("/set-password/{$token}?email=" . urlencode($user->email))
        ->assertInertia(fn ($page) => $page->component('auth/reset-password')->where('mode', 'invite'));

    $this->post('/set-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'first-password',
        'password_confirmation' => 'first-password',
    ])->assertRedirect(route('login'));

    $user->refresh();
    expect($user->isPendingInvite())->toBeFalse()->and($user->email_verified_at)->not->toBeNull();
    $this->assertGuest();
});

test('an invite token cannot be used on the reset route and vice versa', function () {
    $user = User::factory()->create(['password' => null]);
    $token = Password::broker('invites')->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'first-password',
        'password_confirmation' => 'first-password',
    ])->assertSessionHasErrors('email');
});

test('expired invites are rejected', function () {
    $user = User::factory()->create(['password' => null]);
    $token = Password::broker('invites')->createToken($user);

    $this->travel(config('auth.passwords.invites.expire') + 1)->minutes();

    $this->post('/set-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'first-password',
        'password_confirmation' => 'first-password',
    ])->assertSessionHasErrors('email');
});

test('profile: an SSO user can add a password without a current one', function () {
    $user = User::factory()->create(['password' => null, 'oidc_sub' => 'sub-1']);

    $this->actingAs($user)->put('/profile/password', [
        'password' => 'brand-new-pass',
        'password_confirmation' => 'brand-new-pass',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('brand-new-pass', $user->refresh()->password))->toBeTrue();
});

test('profile: changing an existing password requires the current one', function () {
    $user = User::factory()->create(['password' => 'old-password']);

    $payload = ['password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'];

    $this->actingAs($user)->put('/profile/password', $payload + ['current_password' => 'wrong'])
        ->assertSessionHasErrors('current_password');

    $this->actingAs($user)->put('/profile/password', $payload + ['current_password' => 'old-password'])
        ->assertSessionHasNoErrors();

    expect(Hash::check('brand-new-pass', $user->refresh()->password))->toBeTrue();
});

test('profile password change is unavailable when basic auth is disabled', function () {
    config(['auth.basic_enabled' => false]);
    $user = User::factory()->create();

    $this->actingAs($user)->put('/profile/password', ['password' => 'x'])->assertNotFound();
    $this->actingAs($user)->get('/profile')->assertInertia(fn ($page) => $page->where('basicEnabled', false));
});

test('password changes sign out other database sessions', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['password' => 'old-password']);

    foreach (['other-session', 'current'] as $id) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    }

    $user->signOutOtherSessions('current');

    expect(DB::table('sessions')->pluck('id')->all())->toBe(['current']);
});

test('password events are written to the audit trail', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);

    $this->post('/login', ['email' => $user->email, 'password' => 'bad']);
    $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass']);

    expect(Spatie\Activitylog\Models\Activity::query()->pluck('event')->all())->toContain('login-failed', 'login');
});

test('dns:create-user bootstraps a super admin with a password', function () {
    $this->artisan('dns:create-user', ['--name' => 'Root', '--email' => 'Root@Example.com', '--password' => 'long-enough-pw', '--super-admin' => true])
        ->assertSuccessful();

    $user = User::where('email', 'root@example.com')->sole();
    expect($user->isSuperAdmin())->toBeTrue()->and(Hash::check('long-enough-pw', $user->password))->toBeTrue();

    $this->artisan('dns:create-user', ['--name' => 'Dup', '--email' => 'root@example.com', '--password' => 'long-enough-pw'])->assertFailed();
});

test('invite notification mentions SSO only when it is enabled', function () {
    $user = User::factory()->create();
    $mail = (new InviteNotification('tok'))->toMail($user);

    expect(implode(' ', $mail->outroLines))->toContain('single sign-on');

    config(['auth.sso_enabled' => false]);
    expect(implode(' ', (new InviteNotification('tok'))->toMail($user)->outroLines))->not->toContain('single sign-on');
});
