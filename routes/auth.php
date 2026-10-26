<?php

declare(strict_types = 1);

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\OidcController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordLoginController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;

Route::middleware('guest')->group(function () {
    Route::get('login', [OidcController::class, 'show'])->name('login');

    Route::middleware('auth.method:sso')->group(function () {
        Route::get('auth/redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
        Route::get('auth/callback', [OidcController::class, 'callback'])->name('oidc.callback');
    });

    // Basic (email + password) auth. 404s when BASIC_AUTH_ENABLED=false
    // while SSO is on. `broker` is a route default (not user input): the
    // invite routes use the separate `invites` broker (own table + expiry).
    Route::middleware('auth.method:basic')->group(function () {
        Route::post('login', [PasswordLoginController::class, 'store'])->name('login.store');

        Route::get('two-factor-challenge', [TwoFactorChallengeController::class, 'show'])->name('two-factor.challenge');
        Route::post('two-factor-challenge', [TwoFactorChallengeController::class, 'store'])->middleware('throttle:10,1')->name('two-factor.verify');

        Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
        Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:6,1')->name('password.email');

        Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->defaults('broker', 'users')->name('password.reset');
        Route::post('reset-password', [NewPasswordController::class, 'store'])->defaults('broker', 'users')->name('password.store');

        Route::get('set-password/{token}', [NewPasswordController::class, 'create'])->defaults('broker', 'invites')->name('password.invite');
        Route::post('set-password', [NewPasswordController::class, 'store'])->defaults('broker', 'invites')->name('password.invite.store');
    });
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [OidcController::class, 'logout'])->name('logout');
});
