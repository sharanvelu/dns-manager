<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DnsEntryController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\ProviderController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Public: documentation for the INSTALLED version (see docs/content).
Route::get('docs/{page?}', DocsController::class)
    ->where('page', '[a-z0-9\-]+')
    ->name('docs');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Read access: any authenticated user.
    Route::get('entries', [DnsEntryController::class, 'index'])->name('entries.index');
    Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');

    // Mutations require the matching role (or Super Admin).
    Route::middleware('can:manage-entries')->group(function () {
        Route::get('entries/import/sample', [DnsEntryController::class, 'importSample'])->name('entries.import.sample');
        Route::post('entries/import', [DnsEntryController::class, 'import'])->name('entries.import');
        Route::post('entries', [DnsEntryController::class, 'store'])->name('entries.store');
        Route::put('entries/{entry}', [DnsEntryController::class, 'update'])->name('entries.update');
        Route::delete('entries/{entry}', [DnsEntryController::class, 'destroy'])->name('entries.destroy');
        Route::post('entries/{entry}/sync', [DnsEntryController::class, 'sync'])->name('entries.sync');
    });

    Route::middleware('can:manage-providers')->group(function () {
        Route::post('providers', [ProviderController::class, 'store'])->name('providers.store');
        Route::put('providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
        Route::delete('providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');
        Route::post('providers/test', [ProviderController::class, 'test'])->name('providers.test');
        Route::post('providers/{provider}/check', [ProviderController::class, 'check'])->name('providers.check');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
