<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Built-in Scheduler
    |--------------------------------------------------------------------------
    |
    | When enabled, the schedule (run by `php artisan schedule:work`) queues
    | the drift checker on the cron expression below. Disable it if an
    | external system (N8N, cron, ...) triggers drift checks through the
    | webhook endpoint instead.
    |
    */

    'scheduler_enabled' => (bool) env('SCHEDULER_ENABLED', true),

    'drift_check_cron' => env('DRIFT_CHECK_CRON', '*/15 * * * *'),

    /*
    |--------------------------------------------------------------------------
    | External Trigger Token
    |--------------------------------------------------------------------------
    |
    | Bearer token for POST /api/hooks/drift-check, which queues the same
    | drift checks as the scheduler. The endpoint is disabled while this
    | is unset.
    |
    */

    'trigger_token' => env('DRIFT_CHECK_TRIGGER_TOKEN'),

];
