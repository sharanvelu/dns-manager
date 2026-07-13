<?php

use Illuminate\Support\Facades\Schedule;

// The built-in drift-check schedule. Timing comes from DRIFT_CHECK_CRON;
// SCHEDULER_ENABLED=false turns it off entirely (e.g. when an external
// automation tool triggers POST /api/hooks/drift-check instead).
if (config('dns.scheduler_enabled')) {
    Schedule::command('dns:check-drift')
        ->cron(config('dns.drift_check_cron'))
        ->withoutOverlapping()
        ->onOneServer();
}
