<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Built-in Scheduler
    |--------------------------------------------------------------------------
    |
    | When enabled, the schedule (run by `php artisan schedule:work`) queues
    | the drift checker and the provider health checker on the cron
    | expressions below. SCHEDULER_ENABLED=false disables the built-in
    | schedule entirely — use it when an external system (N8N, cron, ...)
    | triggers checks through the webhook endpoints instead. The health
    | check schedule can additionally be turned off on its own.
    |
    */

    'scheduler_enabled' => (bool) env('SCHEDULER_ENABLED', true),

    'drift_check_cron' => env('DRIFT_CHECK_CRON', '*/15 * * * *'),

    'provider_health_check_enabled' => (bool) env('PROVIDER_HEALTH_CHECK_ENABLED', true),

    'provider_health_check_cron' => env('PROVIDER_HEALTH_CHECK_CRON', '*/5 * * * *'),

    /*
    |--------------------------------------------------------------------------
    | External Trigger Token
    |--------------------------------------------------------------------------
    |
    | Bearer token for the POST /api/hooks/* endpoints (drift-check,
    | health-check), which queue the same checks as the scheduler. The
    | endpoints are disabled while this is unset.
    |
    */

    'trigger_token' => env('HOOKS_TRIGGER_TOKEN'),

];
