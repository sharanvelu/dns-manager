<?php

use Illuminate\Support\Facades\Schedule;

// The built-in schedules. Timing comes from DRIFT_CHECK_CRON and
// PROVIDER_HEALTH_CHECK_CRON; SCHEDULER_ENABLED=false turns both off
// entirely (e.g. when an external automation tool triggers
// POST /api/hooks/* instead), and PROVIDER_HEALTH_CHECK_ENABLED=false
// disables just the provider health check.
if (config('dns.scheduler_enabled')) {
    Schedule::command('dns:check-drift')
        ->cron(config('dns.drift_check_cron'))
        ->withoutOverlapping()
        ->onOneServer();

    if (config('dns.provider_health_check_enabled')) {
        Schedule::command('dns:check-provider-health')
            ->cron(config('dns.provider_health_check_cron'))
            ->withoutOverlapping()
            ->onOneServer();
    }
}
