<?php

use App\Http\Controllers\Api\DriftCheckController;
use App\Http\Middleware\AuthenticateTriggerToken;
use Illuminate\Support\Facades\Route;

// External automation hooks (N8N, cron, CI). Guarded by a static bearer
// token (DRIFT_CHECK_TRIGGER_TOKEN); disabled while the token is unset.
Route::post('hooks/drift-check', DriftCheckController::class)
    ->middleware(AuthenticateTriggerToken::class)
    ->name('api.hooks.drift-check');
