<?php

use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/settings/profile')->assertOk();
});

test('name can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/settings/profile', [
            'name' => 'Test User',
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect('/settings/profile');

    expect($user->refresh()->name)->toBe('Test User');
});

test('email cannot be changed via profile update', function () {
    $user = User::factory()->create(['email' => 'fixed@example.com']);

    $this->actingAs($user)->patch('/settings/profile', [
        'name' => 'Test User',
        'email' => 'other@example.com',
    ]);

    expect($user->refresh()->email)->toBe('fixed@example.com');
});
