<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\EntrySyncState;
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
        $state = EntrySyncState::with(['entry.zone', 'zoneProvider.provider', 'zoneProvider.zone'])
            ->find($this->syncStateId);

        if (! $state) {
            return;
        }

        $entry = $state->entry;
        $zoneProvider = $state->zoneProvider;

        if ($zoneProvider && $state->external_id) {
            $zoneProvider->connector()->deleteRecord($state->external_id);
        }

        $state->delete();

        SyncLog::record(
            $zoneProvider?->provider,
            null,
            'delete',
            'success',
            $entry && $zoneProvider
                ? "{$entry->type->value} {$entry->fqdn} removed from {$zoneProvider->label()}"
                : 'Record removed',
            zoneId: $entry?->dns_zone_id,
        );

        // The local entry disappears once the last attachment confirms —
        // but only when the entry is being deleted (all states deleting).
        if ($entry && $entry->syncStates()->count() === 0 && $state->sync_status === SyncStatus::Deleting) {
            $entry->delete();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $state = EntrySyncState::with(['entry', 'zoneProvider.provider'])->find($this->syncStateId);

        $state?->update([
            'sync_status' => SyncStatus::Error,
            'last_error' => $exception?->getMessage(),
        ]);

        SyncLog::record($state?->zoneProvider?->provider, $state?->entry, 'delete', 'error', $exception?->getMessage());
    }
}
