<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\DnsEntryProvider;
use App\Models\SyncLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeleteEntryFromProvider implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60];

    public function __construct(public int $syncStateId) {}

    public function handle(): void
    {
        $state = DnsEntryProvider::with(['entry', 'provider'])->find($this->syncStateId);

        if (! $state) {
            return;
        }

        $entry = $state->entry;
        $provider = $state->provider;

        if ($provider && $state->external_id) {
            $provider->connector()->deleteRecord($state->external_id);
        }

        $state->delete();

        SyncLog::record($provider, null, 'delete', 'success', $entry
            ? "{$entry->type->value} {$entry->name} removed from {$provider?->name}"
            : 'Record removed');

        // The local entry disappears once the last provider confirms —
        // but only when the entry is being deleted (all states deleting).
        if ($entry && $entry->syncStates()->count() === 0 && $state->sync_status === SyncStatus::Deleting) {
            $entry->delete();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $state = DnsEntryProvider::with(['entry', 'provider'])->find($this->syncStateId);

        $state?->update([
            'sync_status' => SyncStatus::Error,
            'last_error' => $exception?->getMessage(),
        ]);

        SyncLog::record($state?->provider, $state?->entry, 'delete', 'error', $exception?->getMessage());
    }
}
