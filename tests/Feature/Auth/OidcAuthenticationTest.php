<?php

declare(strict_types = 1);

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeOidcUser(string $sub = 'sub-123', string $email = 'jane@example.com', string $name = 'Jane Doe'): SocialiteUser
{
    return (new SocialiteUser())->map([
        'id' => $sub,
        'name' => $name,
        'nickname' => 'jane',
        'email' => $email,
    ]);
}

test('login screen renders with the configured provider label', function () {
    config(['services.oidc.label' => 'Sentrix']);

    $response = $this->get('/login');

    $response->assertOk()->assertInertia(
        fn ($page) => $page->component('auth/login')->where('providerLabel', 'Sentrix')
    );
});

test('oidc callback provisions a new user with gravatar avatar and logs them in', function () {
    Socialite::shouldReceive('driver->user')->andReturn(fakeOidcUser());

    $response = $this->get('/auth/callback?code=abc&state=xyz');

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::sole();
    expect($user->email)->toBe('jane@example.com')
        ->and($user->oidc_sub)->toBe('sub-123')
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->avatar_url)->toContain('gravatar.com/avatar/' . hash('sha256', 'jane@example.com'))
        ->and($user->avatar_url)->toContain('d=404');
});

test('oidc callback matches an existing user by sub and refreshes attributes', function () {
    $existing = User::factory()->create([
        'email' => 'old@example.com',
        'oidc_sub' => 'sub-123',
        'name' => 'Old Name',
    ]);

    Socialite::shouldReceive('driver->user')->andReturn(fakeOidcUser());

    $this->get('/auth/callback?code=abc&state=xyz')->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1)
        ->and($existing->refresh()->email)->toBe('jane@example.com')
        ->and($existing->name)->toBe('Jane Doe');
});

test('oidc callback matches an existing user by email and links the sub', function () {
    $existing = User::factory()->create(['email' => 'jane@example.com', 'oidc_sub' => null]);

    Socialite::shouldReceive('driver->user')->andReturn(fakeOidcUser());

    $this->get('/auth/callback?code=abc&state=xyz')->assertRedirect(route('dashboard'));

    expect(User::count())->toBe(1)
        ->and($existing->refresh()->oidc_sub)->toBe('sub-123');
});

test('oidc callback without an email is rejected', function () {
    Socialite::shouldReceive('driver->user')->andReturn(
        (new SocialiteUser())->map(['id' => 'sub-123', 'name' => 'Jane'])
    );

    $response = $this->get('/auth/callback?code=abc&state=xyz');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
});

test('unauthenticated users are redirected to login from protected pages', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('users can log out', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/login');
    $this->assertGuest();
});
