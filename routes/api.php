<?php

use App\Http\Controllers\Api\DriftCheckController;
use App\Http\Controllers\Api\HealthCheckController;
use App\Http\Middleware\AuthenticateTriggerToken;
use Illuminate\Support\Facades\Route;

// External automation hooks (N8N, cron, CI). Guarded by a static bearer
// token (HOOKS_TRIGGER_TOKEN); disabled while the token is unset.
Route::middleware(AuthenticateTriggerToken::class)->group(function () {
    Route::post('hooks/drift-check', DriftCheckController::class)
        ->name('api.hooks.drift-check');

    Route::post('hooks/health-check', HealthCheckController::class)
        ->name('api.hooks.health-check');
});
