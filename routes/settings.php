<?php

use App\Http\Controllers\Settings\ActivityLogController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    Route::middleware('can:manage-users')->group(function () {
        Route::get('settings/users', [UserController::class, 'index'])->name('users.index');
        Route::put('settings/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('settings/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Audit trail viewer — Super Admin only. `data` serves JSON for the
    // activity dialog opened from the entries/providers kebab menus.
    Route::middleware('can:view-activity')->group(function () {
        Route::get('settings/activity', [ActivityLogController::class, 'index'])->name('activity.index');
        Route::get('settings/activity/data', [ActivityLogController::class, 'data'])->name('activity.data');
    });
});
