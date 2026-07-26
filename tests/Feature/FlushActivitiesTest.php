<?php

use Spatie\Activitylog\Models\Activity;

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

test('rejects a non-numeric --days', function () {
    makeActivity('kept');

    $this->artisan('dns:flush-activities', ['--days' => 'soon', '--force' => true])
        ->expectsOutputToContain('--days must be a positive integer.')
        ->assertExitCode(2);

    expect(Activity::count())->toBe(1);
});
