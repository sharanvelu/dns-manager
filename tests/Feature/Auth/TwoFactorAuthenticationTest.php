<?php

declare(strict_types = 1);

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

const TF_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

function otp(string $secret = TF_SECRET): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

function twoFactorUser(array $attributes = []): User
{
    return User::factory()->withTwoFactor(TF_SECRET)->create(['password' => 'secret-pass'] + $attributes);
}

function challengeSession(User $user, bool $remember = false, ?string $startedAt = null): array
{
    return [
        'two_factor.id' => $user->id,
        'two_factor.remember' => $remember,
        'two_factor.started_at' => $startedAt ?? now()->toIso8601String(),
    ];
}

test('the profile exposes two-factor state without the raw secret', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);

    $this->actingAs($user)->get('/profile')->assertInertia(
        fn ($page) => $page->where('twoFactor.enabled', false)->where('twoFactor.pending', null)->where('twoFactor.recoveryCodes', null)
    );

    $enabled = twoFactorUser();
    $response = $this->actingAs($enabled)->get('/profile');

    $response->assertInertia(fn ($page) => $page->where('twoFactor.enabled', true)->where('twoFactor.pending', null));
    expect($response->getContent())->not->toContain(TF_SECRET);
});

test('enabling starts a pending enrollment and re-enabling rotates the secret', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);

    $this->actingAs($user)->post('/profile/two-factor')->assertRedirect();
    $first = $user->fresh()->two_factor_secret;

    expect($first)->toHaveLength(32)
        ->and($user->fresh()->hasPendingTwoFactor())->toBeTrue()
        ->and($user->fresh()->two_factor_recovery_codes)->toHaveCount(8);

    $this->actingAs($user)->get('/profile')->assertInertia(
        fn ($page) => $page->where('twoFactor.enabled', false)
            ->where('twoFactor.pending.secret', $first)
            ->where('twoFactor.pending.qrSvg', fn ($svg) => str_starts_with($svg, '<svg') && ! str_contains($svg, '<?xml'))
    );

    $this->actingAs($user)->post('/profile/two-factor');

    expect($user->fresh()->two_factor_secret)->not->toBe($first);
});

test('enabling is refused once two-factor is confirmed', function () {
    $user = twoFactorUser();

    $this->actingAs($user)->post('/profile/two-factor')->assertSessionHasErrors('code');

    expect($user->fresh()->two_factor_secret)->toBe(TF_SECRET);
});

test('a wrong confirmation code is rejected', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);
    $this->actingAs($user)->post('/profile/two-factor');

    $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
    $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => 'abc'])->assertSessionHasErrors('code');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

test('confirming enables two-factor, flashes recovery codes once and logs without secrets', function () {
    $this->freezeTime();
    $user = User::factory()->create(['password' => 'secret-pass']);
    $this->actingAs($user)->post('/profile/two-factor');
    $secret = $user->fresh()->two_factor_secret;

    $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => otp($secret)])->assertSessionHas('success');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs($user)->get('/profile')->assertInertia(
        fn ($page) => $page->where('twoFactor.enabled', true)->has('twoFactor.recoveryCodes', 8)
    );
    $this->actingAs($user)->get('/profile')->assertInertia(fn ($page) => $page->where('twoFactor.recoveryCodes', null));

    $activity = Activity::query()->where('event', 'two-factor-enabled')->sole();
    expect($activity->causer_id)->toBe($user->id)
        ->and(json_encode($activity->properties))->not->toContain($secret);
});

test('disabling and regenerating recovery codes require the current password', function () {
    $user = twoFactorUser();
    $codes = $user->two_factor_recovery_codes;

    $this->actingAs($user)->delete('/profile/two-factor', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
    $this->actingAs($user)->post('/profile/two-factor/recovery-codes', ['current_password' => 'wrong'])->assertSessionHasErrors('current_password');

    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($user->fresh()->two_factor_recovery_codes)->toBe($codes);

    $this->actingAs($user)->post('/profile/two-factor/recovery-codes', ['current_password' => 'secret-pass'])
        ->assertSessionHas('twoFactorRecoveryCodes');

    expect($user->fresh()->two_factor_recovery_codes)->toHaveCount(8)->not->toBe($codes)
        ->and(Activity::query()->where('event', 'two-factor-recovery-codes-regenerated')->count())->toBe(1);

    $this->actingAs($user)->delete('/profile/two-factor', ['current_password' => 'secret-pass'])->assertSessionHas('success');

    $fresh = $user->fresh();
    expect($fresh->hasTwoFactorEnabled())->toBeFalse()
        ->and($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and(Activity::query()->where('event', 'two-factor-disabled')->count())->toBe(1);
});

test('regenerating recovery codes is refused when two-factor is off', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);

    $this->actingAs($user)->post('/profile/two-factor/recovery-codes', ['current_password' => 'secret-pass'])
        ->assertSessionHasErrors('current_password');
});

test('cancelling a pending enrollment logs nothing', function () {
    $user = User::factory()->create(['password' => 'secret-pass']);
    $this->actingAs($user)->post('/profile/two-factor');

    $this->actingAs($user)->delete('/profile/two-factor', ['current_password' => 'secret-pass'])->assertSessionHas('success');

    expect($user->fresh()->two_factor_secret)->toBeNull()
        ->and(Activity::query()->where('event', 'two-factor-disabled')->count())->toBe(0);
});

test('password sign-in with two-factor parks the user at the challenge', function () {
    $user = twoFactorUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'secret-pass', 'remember' => true])
        ->assertRedirect(route('two-factor.challenge'))
        ->assertSessionHas('two_factor.id', $user->id)
        ->assertSessionHas('two_factor.remember', true);

    $this->assertGuest();
    expect(Activity::query()->where('event', 'login')->count())->toBe(0);

    $this->get('/two-factor-challenge')->assertInertia(fn ($page) => $page->component('auth/two-factor-challenge'));
});

test('a valid authenticator code completes the sign-in', function () {
    $this->freezeTime();
    $user = twoFactorUser();

    $this->withSession(challengeSession($user))
        ->post('/two-factor-challenge', ['code' => otp()])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);

    $login = Activity::query()->where('event', 'login')->sole();
    expect($login->causer_id)->toBe($user->id)
        ->and($login->properties['method'])->toBe('password')
        ->and($login->properties['two_factor'])->toBeTrue()
        ->and($login->properties['recovery_code'])->toBeFalse();
});

test('the remember choice survives the challenge', function () {
    $this->freezeTime();
    $user = twoFactorUser();

    $response = $this->withSession(challengeSession($user, remember: true))->post('/two-factor-challenge', ['code' => otp()]);

    $response->assertCookie(auth()->getRecallerName());
});

test('a wrong authenticator code is rejected and logged without a causer', function () {
    $user = twoFactorUser();

    $this->withSession(challengeSession($user))
        ->post('/two-factor-challenge', ['code' => '000000'])
        ->assertSessionHasErrors('code');

    $this->assertGuest();

    $failed = Activity::query()->where('event', 'two-factor-challenge-failed')->sole();
    expect($failed->causer_id)->toBeNull()
        ->and($failed->subject_id)->toBe($user->id);
});

test('a recovery code works exactly once', function () {
    $user = twoFactorUser();

    $this->withSession(challengeSession($user))
        ->post('/two-factor-challenge', ['recovery_code' => 'AAAAAAAAAA-BBBBBBBBBB'])
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->two_factor_recovery_codes)->toBe(['CCCCCCCCCC-DDDDDDDDDD'])
        ->and(Activity::query()->where('event', 'login')->sole()->properties['recovery_code'])->toBeTrue();

    auth()->logout();

    $this->withSession(challengeSession($user))
        ->post('/two-factor-challenge', ['recovery_code' => 'AAAAAAAAAA-BBBBBBBBBB'])
        ->assertSessionHasErrors('recovery_code');

    $this->assertGuest();
});

test('an authenticator code cannot be replayed', function () {
    $this->freezeTime();
    $user = twoFactorUser();
    $code = otp();

    $this->withSession(challengeSession($user))->post('/two-factor-challenge', ['code' => $code])->assertRedirect(route('dashboard'));
    auth()->logout();

    $this->withSession(challengeSession($user))->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('the challenge expires after ten minutes', function () {
    $this->freezeTime();
    $user = twoFactorUser();
    $session = challengeSession($user);

    $this->travel(11)->minutes();

    $this->withSession($session)->get('/two-factor-challenge')->assertRedirect(route('login'));
    $this->withSession($session)->post('/two-factor-challenge', ['code' => otp()])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('the challenge without a pending sign-in goes back to login', function () {
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
    $this->post('/two-factor-challenge', ['code' => '123456'])->assertRedirect(route('login'));
});

test('challenge attempts are throttled', function () {
    $user = twoFactorUser();

    foreach (range(1, 10) as $ignored) {
        $this->withSession(challengeSession($user))->post('/two-factor-challenge', ['code' => '000000']);
    }

    $this->withSession(challengeSession($user))->post('/two-factor-challenge', ['code' => '000000'])->assertTooManyRequests();
});

test('the challenge and enrollment routes are unavailable when basic auth is off', function () {
    config(['auth.sso_enabled' => true, 'auth.basic_enabled' => false]);
    $user = twoFactorUser();

    $this->get('/two-factor-challenge')->assertNotFound();
    $this->actingAs($user)->post('/profile/two-factor')->assertNotFound();
});

test('an admin can reset a user\'s two-factor authentication', function () {
    config(['session.driver' => 'database']);
    $admin = User::factory()->create();
    $user = twoFactorUser();
    DB::table('sessions')->insert(['id' => 'theirs', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($admin)->delete("/users/{$user->id}/two-factor")->assertSessionHas('success');

    $fresh = $user->fresh();
    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull()
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    $reset = Activity::query()->where('event', 'two-factor-reset')->sole();
    expect($reset->causer_id)->toBe($admin->id)
        ->and($reset->subject_id)->toBe($user->id);
});

test('an admin can reset an abandoned pending enrollment', function () {
    $admin = User::factory()->create();
    $user = User::factory()->create(['password' => 'secret-pass', 'two_factor_secret' => TF_SECRET]);

    $this->actingAs($admin)->delete("/users/{$user->id}/two-factor")->assertSessionHas('success');

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('two-factor reset guards', function () {
    $admin = twoFactorUser();
    $plain = User::factory()->create(['password' => 'secret-pass']);

    $this->actingAs($admin)->delete("/users/{$admin->id}/two-factor")->assertSessionHasErrors('user');
    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->actingAs(User::factory()->create())->delete("/users/{$plain->id}/two-factor")->assertSessionHasErrors('user');

    $this->actingAs(User::factory()->superViewer()->create())->delete("/users/{$admin->id}/two-factor")->assertForbidden();
    expect($admin->fresh()->hasTwoFactorEnabled())->toBeTrue();
});

test('a user admin can reset another user but basic auth off blocks it', function () {
    $target = twoFactorUser();

    $this->actingAs(User::factory()->userAdmin()->create())->delete("/users/{$target->id}/two-factor")->assertSessionHas('success');

    $other = twoFactorUser();
    config(['auth.sso_enabled' => true, 'auth.basic_enabled' => false]);

    $this->actingAs(User::factory()->create())->delete("/users/{$other->id}/two-factor")->assertNotFound();
});

test('the user detail page reports whether two-factor is enabled', function () {
    $admin = User::factory()->create();
    $user = twoFactorUser();

    $this->actingAs($admin)->get("/users/{$user->id}")->assertInertia(fn ($page) => $page->where('managedUser.twoFactorEnabled', true));
});

test('two-factor secrets are never serialized or logged', function () {
    $this->freezeTime();
    $user = User::factory()->create(['password' => 'secret-pass']);
    $this->actingAs($user)->post('/profile/two-factor');
    $secret = $user->fresh()->two_factor_secret;
    $this->actingAs($user)->post('/profile/two-factor/confirm', ['code' => otp($secret)]);
    $this->actingAs($user)->post('/profile/two-factor/recovery-codes', ['current_password' => 'secret-pass']);

    $array = $user->fresh()->toArray();
    expect($array)->not->toHaveKeys(['two_factor_secret', 'two_factor_recovery_codes']);

    $logged = Activity::query()->get()->map(fn ($a) => json_encode($a->properties))->implode('');
    expect($logged)->not->toContain($secret);
});
