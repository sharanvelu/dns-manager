<?php

declare(strict_types = 1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Notification;
use App\Notifications\ResetPasswordNotification;

beforeEach(function () {
    config(['auth.basic_enabled' => true, 'auth.sso_enabled' => true]);
});

test('forcing a reset flags the user, signs them out, emails a link and audits it', function () {
    Notification::fake();
    config(['session.driver' => 'database']);
    $admin = User::factory()->create();
    $target = User::factory()->noRoles()->create(['remember_token' => 'old-remember-token']);
    $hash = $target->password;

    foreach ([['t-1', $target->id], ['t-2', $target->id], ['admin-1', $admin->id]] as [$id, $userId]) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
    }

    $this->actingAs($admin)
        ->post("/users/{$target->id}/force-password-reset")
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $target->refresh();
    expect($target->must_change_password)->toBeTrue()
        ->and($target->password)->toBe($hash)
        ->and($target->remember_token)->not->toBe('old-remember-token')
        ->and(DB::table('sessions')->where('user_id', $target->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'admin-1')->exists())->toBeTrue();

    Notification::assertSentTo($target, ResetPasswordNotification::class);

    $activity = Activity::query()->where('event', 'password-reset-forced')->sole();
    expect($activity->log_name)->toBe('auth')
        ->and($activity->causer_id)->toBe($admin->id)
        ->and($activity->subject_id)->toBe($target->id)
        ->and($activity->properties->toArray())->toBe(['email_sent' => true]);
});

test('an admin cannot force a reset on their own account', function () {
    Notification::fake();
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->post("/users/{$admin->id}/force-password-reset")
        ->assertSessionHasErrors(['user' => 'You cannot force a reset on your own account — change your password from your profile.']);

    expect($admin->refresh()->must_change_password)->toBeFalse();
    Notification::assertNothingSent();
});

test('a user admin cannot reset themselves but can reset another user', function () {
    Notification::fake();
    $userAdmin = User::factory()->userAdmin()->create();
    $target = User::factory()->noRoles()->create();

    $this->actingAs($userAdmin)->post("/users/{$userAdmin->id}/force-password-reset")->assertSessionHasErrors('user');
    $this->actingAs($userAdmin)->post("/users/{$target->id}/force-password-reset")->assertSessionHasNoErrors();

    expect($target->refresh()->must_change_password)->toBeTrue();
    Notification::assertSentTo($target, ResetPasswordNotification::class);
});

test('a pending invite is pointed at resend invite', function () {
    Notification::fake();
    $admin = User::factory()->create();
    $invited = User::factory()->noRoles()->create(['password' => null]);

    $this->actingAs($admin)
        ->post("/users/{$invited->id}/force-password-reset")
        ->assertSessionHasErrors('user');

    expect(session('errors')->first('user'))->toContain('Resend invite');
    expect($invited->refresh()->must_change_password)->toBeFalse();
    Notification::assertNothingSent();
});

test('an SSO-linked user without a password has nothing to reset', function () {
    Notification::fake();
    $admin = User::factory()->create();
    $sso = User::factory()->noRoles()->create(['password' => null, 'oidc_sub' => 'sub-123']);

    $this->actingAs($admin)
        ->post("/users/{$sso->id}/force-password-reset")
        ->assertSessionHasErrors('user');

    expect(session('errors')->first('user'))->toContain('has no password to reset');
    expect($sso->refresh()->must_change_password)->toBeFalse();
    Notification::assertNothingSent();
});

test('an SSO-only user is refused while SSO is on and allowed when SSO is off', function () {
    Notification::fake();
    $admin = User::factory()->create();
    $target = User::factory()->noRoles()->create(['sso_only' => true]);

    $this->actingAs($admin)->post("/users/{$target->id}/force-password-reset")->assertSessionHasErrors('user');
    expect($target->refresh()->must_change_password)->toBeFalse();
    Notification::assertNothingSent();

    config(['auth.sso_enabled' => false]);

    $this->actingAs($admin)->post("/users/{$target->id}/force-password-reset")->assertSessionHasNoErrors();
    expect($target->refresh()->must_change_password)->toBeTrue();
    Notification::assertSentTo($target, ResetPasswordNotification::class);
});

test('super viewers cannot force a reset', function () {
    Notification::fake();
    $viewer = User::factory()->superViewer()->create();
    $target = User::factory()->noRoles()->create();

    $this->actingAs($viewer)->post("/users/{$target->id}/force-password-reset")->assertForbidden();

    expect($target->refresh()->must_change_password)->toBeFalse();
});

test('forcing a reset is unavailable when basic auth is disabled', function () {
    config(['auth.sso_enabled' => true, 'auth.basic_enabled' => false]);
    $admin = User::factory()->create();
    $target = User::factory()->noRoles()->create();

    $this->actingAs($admin)->post("/users/{$target->id}/force-password-reset")->assertNotFound();
});

test('a throttled resend still flags and signs out but sends nothing new', function () {
    Notification::fake();
    $admin = User::factory()->create();
    $target = User::factory()->noRoles()->create();
    Password::broker('users')->createToken($target);

    $this->actingAs($admin)
        ->post("/users/{$target->id}/force-password-reset")
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect(session('success'))->toContain('already sent');
    expect($target->refresh()->must_change_password)->toBeTrue();
    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'password-reset-forced')->sole()->properties['email_sent'])->toBeFalse();
});

test('a flagged user is blocked at login and emailed a reset link', function () {
    Notification::fake();
    $target = User::factory()->create(['password' => 'secret-pass', 'must_change_password' => true]);

    $this->post('/login', ['email' => $target->email, 'password' => 'secret-pass'])
        ->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain('administrator requires');
    $this->assertGuest();
    Notification::assertSentTo($target, ResetPasswordNotification::class);
    expect(Activity::query()->where('event', 'login')->exists())->toBeFalse();
});

test('resetting through the emailed token clears the flag and sign-in works again', function () {
    $target = User::factory()->create(['password' => 'old-password', 'must_change_password' => true]);
    $token = Password::broker('users')->createToken($target);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $target->email,
        'password' => 'new-password-1',
        'password_confirmation' => 'new-password-1',
    ])->assertRedirect(route('login'));

    expect($target->refresh()->must_change_password)->toBeFalse();

    $this->post('/login', ['email' => $target->email, 'password' => 'new-password-1'])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($target);
});

test('changing the password from the profile clears the flag', function () {
    $user = User::factory()->create(['password' => 'old-password', 'must_change_password' => true]);

    $this->actingAs($user)->put('/profile/password', [
        'current_password' => 'old-password',
        'password' => 'brand-new-pass',
        'password_confirmation' => 'brand-new-pass',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->must_change_password)->toBeFalse()
        ->and(Hash::check('brand-new-pass', $user->password))->toBeTrue();
});

test('the users index and detail page expose mustChangePassword', function () {
    $admin = User::factory()->create();
    $flagged = User::factory()->noRoles()->create(['must_change_password' => true]);

    $users = $this->actingAs($admin)->get('/users')->assertOk()->viewData('page')['props']['users'];

    expect(collect($users)->pluck('mustChangePassword', 'id')->all())->toEqual([$admin->id => false, $flagged->id => true]);

    $this->actingAs($admin)->get("/users/{$flagged->id}")->assertInertia(
        fn ($page) => $page->where('managedUser.mustChangePassword', true)
    );
});
