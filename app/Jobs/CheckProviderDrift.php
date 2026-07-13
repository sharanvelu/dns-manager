<?php

namespace App\Jobs;

use App\Enums\HealthStatus;
use App\Enums\SyncStatus;
use App\Models\Provider;
use App\Models\SyncLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class CheckProviderDrift implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $providerId) {}

    public function handle(): void
    {
        $provider = Provider::find($this->providerId);

        if (! $provider || ! $provider->enabled) {
            return;
        }

        $connector = $provider->connector();
        $capabilities = $connector::capabilities();

        try {
            $remote = $connector->listRecords();
        } catch (Throwable $e) {
            $provider->update([
                'health_status' => HealthStatus::Error,
                'health_message' => $e->getMessage(),
                'last_checked_at' => now(),
            ]);

            SyncLog::record($provider, null, 'drift-check', 'error', $e->getMessage());

            return;
        }

        $drifted = 0;

        $states = $provider->syncStates()
            ->whereIn('sync_status', [SyncStatus::Synced, SyncStatus::Drifted])
            ->whereNotNull('external_id')
            ->with('entry')
            ->get();

        foreach ($states as $state) {
            $entry = $state->entry;

            $match = $remote
                ->where('externalId', $state->external_id)
                ->first(fn ($record) => $record->matches($entry, $capabilities));

            if ($match) {
                $state->update(['sync_status' => SyncStatus::Synced, 'last_error' => null]);

                continue;
            }

            $missing = $remote->where('externalId', $state->external_id)->isEmpty();

            $state->update([
                'sync_status' => SyncStatus::Drifted,
                'last_error' => $missing
                    ? 'Record no longer exists at the provider.'
                    : 'Remote record differs from the managed entry.',
            ]);

            $drifted++;
        }

        $provider->update([
            'health_status' => HealthStatus::Ok,
            'health_message' => null,
            'last_checked_at' => now(),
        ]);

        SyncLog::record($provider, null, 'drift-check', 'success', sprintf(
            'Checked %d record(s), %d drifted', $states->count(), $drifted,
        ));
    }
}
