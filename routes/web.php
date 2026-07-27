<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DnsEntryBulkController;
use App\Http\Controllers\DnsEntryController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\ProviderImportController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ZoneAccessController;
use App\Http\Controllers\ZoneController;
use App\Http\Controllers\ZoneProviderController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

// Public: documentation for the INSTALLED version (see docs/content).
Route::get('docs/{page?}', DocsController::class)
    ->where('page', '[a-z0-9\-]+')
    ->name('docs');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // The user's own profile — a single page that also carries the
    // appearance preference. No settings section exists beyond this.
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');

    // Listings scope themselves to the user's accessible zones inside the
    // controller — any authenticated user may load them.
    Route::get('entries', [DnsEntryController::class, 'index'])->name('entries.index');
    Route::get('zones', [ZoneController::class, 'index'])->name('zones.index');

    // Entry mutations authorize per zone inside DnsEntryRequest / the
    // controller — the zone comes from the payload or the entry, not the URL.
    // The sample CSV is deliberately open to any authenticated user.
    Route::get('entries/import/sample', [DnsEntryController::class, 'importSample'])->name('entries.import.sample');
    Route::post('entries/import', [DnsEntryController::class, 'import'])->name('entries.import');

    // Bulk actions — literal "bulk" paths must precede the {entry} routes.
    // Ids in zones the user cannot manage silently shrink the selection.
    Route::post('entries/bulk/sync', [DnsEntryBulkController::class, 'sync'])->name('entries.bulk.sync');
    Route::post('entries/bulk/providers', [DnsEntryBulkController::class, 'providers'])->name('entries.bulk.providers');
    Route::patch('entries/bulk', [DnsEntryBulkController::class, 'update'])->name('entries.bulk.update');
    Route::delete('entries/bulk', [DnsEntryBulkController::class, 'destroy'])->name('entries.bulk.destroy');

    Route::post('entries', [DnsEntryController::class, 'store'])->name('entries.store');
    Route::put('entries/{entry}', [DnsEntryController::class, 'update'])->name('entries.update');
    Route::delete('entries/{entry}', [DnsEntryController::class, 'destroy'])->name('entries.destroy');
    Route::post('entries/{entry}/sync', [DnsEntryController::class, 'sync'])->name('entries.sync');

    // Zone CRUD — creation is global (Super Admin), the rest is per zone.
    Route::post('zones', [ZoneController::class, 'store'])->name('zones.store')->middleware('can:create-zones');
    Route::put('zones/{zone}', [ZoneController::class, 'update'])->name('zones.update')->middleware('can:update,zone');
    Route::delete('zones/{zone}', [ZoneController::class, 'destroy'])->name('zones.destroy')->middleware('can:delete,zone');

    Route::middleware('can:view,zone')->group(function () {
        // zones.show only redirects to the Records tab — kept so every
        // existing /zones/{id} link keeps working.
        Route::get('zones/{zone}', [ZoneController::class, 'show'])->name('zones.show');
        Route::get('zones/{zone}/records', [ZoneController::class, 'records'])->name('zones.records');
        Route::get('zones/{zone}/providers', [ZoneController::class, 'providers'])->name('zones.providers');
    });

    // Zone audit trail — `data` serves JSON for the activity dialog opened
    // from the records kebab menu.
    Route::middleware('can:viewActivity,zone')->group(function () {
        Route::get('zones/{zone}/activity', [ZoneController::class, 'activity'])->name('zones.activity');
        Route::get('zones/{zone}/activity/data', [ZoneController::class, 'activityData'])->name('zones.activity.data');
    });

    // Zone access page — Super Viewers read it, User Admins and the zone's
    // Zone Admins manage it ('access' is a fixed segment like 'records').
    Route::get('zones/{zone}/access', [ZoneAccessController::class, 'index'])
        ->name('zones.access')
        ->middleware('can:viewAccess,zone');

    Route::middleware('can:manageRecords,zone')->group(function () {
        Route::post('zones/{zone}/sync', [ZoneController::class, 'syncAll'])->name('zones.sync');
        Route::post('zones/{zone}/sync-drifted', [ZoneController::class, 'syncDrifted'])->name('zones.sync-drifted');

        // Importing from a zone attachment creates/updates DNS entries, so it
        // requires record management even though it hangs off a provider.
        Route::get('zones/{zone}/providers/{zoneProvider}/remote-records', [ProviderImportController::class, 'records'])->name('zones.import.records')->scopeBindings();
        Route::post('zones/{zone}/providers/{zoneProvider}/import', [ProviderImportController::class, 'store'])->name('zones.import')->scopeBindings();
    });

    Route::middleware('can:manageAttachments,zone')->group(function () {
        // Literal "discover" path must precede the {zoneProvider} routes.
        Route::post('zones/{zone}/providers/discover', [ZoneProviderController::class, 'discover'])->name('zone-providers.discover');
        Route::post('zones/{zone}/providers', [ZoneProviderController::class, 'store'])->name('zone-providers.store');
        Route::put('zones/{zone}/providers/{zoneProvider}', [ZoneProviderController::class, 'update'])->name('zone-providers.update')->scopeBindings();
        Route::delete('zones/{zone}/providers/{zoneProvider}', [ZoneProviderController::class, 'destroy'])->name('zone-providers.destroy')->scopeBindings();
        Route::post('zones/{zone}/providers/{zoneProvider}/test', [ZoneProviderController::class, 'test'])->name('zone-providers.test')->scopeBindings();
    });

    // Provider credentials are global — Super Admin manages them, Super
    // Viewer may look at the (secret-blanked) list.
    Route::get('providers', [ProviderController::class, 'index'])->name('providers.index')->middleware('can:view-providers');

    Route::middleware('can:manage-providers')->group(function () {
        Route::post('providers', [ProviderController::class, 'store'])->name('providers.store');
        Route::put('providers/{provider}', [ProviderController::class, 'update'])->name('providers.update');
        Route::delete('providers/{provider}', [ProviderController::class, 'destroy'])->name('providers.destroy');
        Route::post('providers/test', [ProviderController::class, 'test'])->name('providers.test');
        Route::post('providers/{provider}/check', [ProviderController::class, 'check'])->name('providers.check');
    });

    // Zone access grants — User Admins and the zone's Zone Admins. Grant-target
    // restrictions for zone-admin actors live in ZoneAccessController.
    Route::middleware('can:manageAccess,zone')->group(function () {
        Route::put('zones/{zone}/access/{user}', [ZoneAccessController::class, 'upsert'])->name('zones.access.upsert');
        Route::delete('zones/{zone}/access/{user}', [ZoneAccessController::class, 'destroy'])->name('zones.access.destroy');
    });

    // User management — Super Viewer + User Admin may look, Super Admin +
    // User Admin may change (with in-controller self/escalation/last-SA guards).
    Route::middleware('can:view-users')->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('users/{user}', [UserController::class, 'show'])->name('users.show');
    });

    Route::middleware('can:manage-users')->group(function () {
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // Global audit trail — Super Admin + Super Viewer. `data` serves JSON for
    // the activity dialog opened from the entry/provider/zone kebab menus.
    Route::middleware('can:view-global-activity')->group(function () {
        Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');
        Route::get('activity/data', [ActivityLogController::class, 'data'])->name('activity.data');
    });
});

require __DIR__.'/auth.php';
