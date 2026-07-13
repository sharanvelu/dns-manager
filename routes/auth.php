<?php

use App\Http\Controllers\Auth\OidcController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [OidcController::class, 'show'])->name('login');
    Route::get('auth/redirect', [OidcController::class, 'redirect'])->name('oidc.redirect');
    Route::get('auth/callback', [OidcController::class, 'callback'])->name('oidc.callback');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [OidcController::class, 'logout'])->name('logout');
});
