<?php

declare(strict_types = 1);

use Spatie\Activitylog\Models\Activity;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Re-evaluate routes/console.php against a fresh Schedule so tests can
 * exercise the config-dependent registration (the real file already ran
 * at boot, before the test could touch config).
 */
function scheduledCommands(): array
{
    app()->forgetInstance(Schedule::class);
    Illuminate\Support\Facades\Schedule::clearResolvedInstance(Schedule::class);

    $schedule = app(Schedule::class);

    require base_path('routes/console.php');

    return collect($schedule->events())
        ->map(fn ($event) => ['command' => (string) $event->command, 'expression' => $event->expression])
        ->all();
}

function makeActivity(string $description, ?int $daysAgo = null): Activity
{
    $activity = activity('users')->log($description);

    if ($daysAgo !== null) {
        $activity->created_at = now()->subDays($daysAgo);
        $activity->save();
    }

    return $activity;
}

test('flushes all activities with --force', function () {
    makeActivity('one');
    makeActivity('two', 400);

    $this->artisan('dns:flush-activities', ['--force' => true])
        ->expectsOutputToContain('Deleted 2 activity record(s).')
        ->assertSuccessful();

    expect(Activity::count())->toBe(0);
});

test('--days only deletes activities older than the window', function () {
    makeActivity('fresh');
    makeActivity('stale', 31);

    $this->artisan('dns:flush-activities', ['--days' => '30', '--force' => true])
        ->expectsOutputToContain('Deleted 1 activity record(s).')
        ->assertSuccessful();

    expect(Activity::pluck('description')->all())->toBe(['fresh']);
});

test('asks for confirmation and aborts without it', function () {
    makeActivity('kept');

    $this->artisan('dns:flush-activities')
        ->expectsConfirmation('Permanently delete all 1 activity record(s)?', 'no')
        ->assertFailed();

    expect(Activity::count())->toBe(1);
});

test('reports when there is nothing to delete', function () {
    $this->artisan('dns:flush-activities', ['--force' => true])
        ->expectsOutputToContain('No activities to delete.')
        ->assertSuccessful();
});

test('retention schedule runs daily when ACTIVITY_LOGS_RETENTION_DAYS is set', function () {
    config(['dns.activity_logs_retention_days' => 90]);

    $flush = collect(scheduledCommands())->first(fn ($event) => str_contains($event['command'], 'dns:flush-activities'));

    expect($flush)->not->toBeNull()
        ->and($flush['command'])->toContain('--days=90 --force')
        ->and($flush['expression'])->toBe('0 0 * * *');
});

test('retention schedule is absent when the env is unset or invalid', function () {
    foreach ([null, 0, -5, 'soon'] as $value) {
        config(['dns.activity_logs_retention_days' => $value]);

        $commands = collect(scheduledCommands())->pluck('command');

        expect($commands->contains(fn ($command) => str_contains($command, 'dns:flush-activities')))->toBeFalse();
    }
});

test('retention schedule registers even with the built-in scheduler disabled', function () {
    config(['dns.scheduler_enabled' => false, 'dns.activity_logs_retention_days' => 30]);

    $commands = collect(scheduledCommands())->pluck('command');

    expect($commands->contains(fn ($command) => str_contains($command, 'dns:flush-activities')))->toBeTrue()
        ->and($commands->contains(fn ($command) => str_contains($command, 'dns:check-drift')))->toBeFalse();
});

test('rejects a non-numeric --days', function () {
    makeActivity('kept');

    $this->artisan('dns:flush-activities', ['--days' => 'soon', '--force' => true])
        ->expectsOutputToContain('--days must be a positive integer.')
        ->assertExitCode(2);

    expect(Activity::count())->toBe(1);
});
